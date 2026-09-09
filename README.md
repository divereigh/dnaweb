# DNAWeb

Laravel 12 + Inertia 2 + Vue 3 viewer/editor for the `dnaweb` MariaDB database. Replaces the
earlier Django app (archived at `~/dnaweb-django-backup`).

DNAWeb is one of three cooperating pieces:

| Piece | Where | Role |
|-------|-------|------|
| This app | `/var/www/dnaweb` (prod), `~/dnaweb` (dev) | Web UI. Reads everything; writes a deliberately narrow set of columns. |
| Perl loaders + queue workers | `~/ancestry/program` on the dev box, `/home/damo/ancestry-program` on production | Own the schema. Fetch from Ancestry, fill the data tables, drain the queues. |
| Perl API (Mojolicious) | `.../perl-api`, `127.0.0.1:8082` | Sync HTTP wrapper over `treelib`, called from `App\Services\PerlApi`. |

The MariaDB schema is owned by the Perl side; this app must NOT run Laravel migrations against
the data tables. `database/migrations/` holds only Laravel's own users / cache / jobs / sessions
tables. Data-table DDL that this app depends on is checked in as one-shot `.sql` files under
`deploy/` — see [Database objects](#database-objects-one-shot-sql).

## Local development

Requirements: PHP 8.4, Composer 2, Node 22+, MariaDB 10.11.

```bash
composer install
npm install
cp .env.example .env
# edit .env — DB credentials, ADMIN_EMAIL/PASSWORD
php artisan key:generate
php artisan migrate           # Laravel-owned tables only
php artisan db:seed            # creates the admin user from .env
composer dev                   # server:8765 + queue + pail + vite, all in one
```

`composer dev` is the usual entry point. To run the pieces separately:

```bash
php artisan serve --port=8765  # port 8000 is taken on the dev box
npm run dev                    # in another terminal — Vite hot reload
```

Login at `http://127.0.0.1:8765/login` with the email/password from `.env`.

The dev box talks to **production's** database and Perl API over SSH tunnels — `.env` points
`DB_HOST/DB_PORT` and `PERL_API_URL` at the local ends of those. Bring the tunnels up before
starting the app. `PerlApi` returns `null` on any failure (connection refused, timeout, HTTP
error) rather than throwing, so the app degrades instead of 500ing when the API end is down;
the DB end has no such fallback.

### Tests

```bash
composer test                                    # config:clear, then artisan test
php artisan test --filter=AuthenticationTest     # one class
php artisan test tests/Feature/ProfileTest.php   # one file
./vendor/bin/pint                                # formatter (Laravel defaults)
```

`phpunit.xml` forces `DB_CONNECTION=sqlite` / `:memory:`, so tests can never touch the real
schema — and equally, nothing that queries the real tables can be covered by them. The suite is
Breeze's auth/profile defaults. Verify service-layer changes by loading the page.

## Project layout

| Path | Purpose |
|------|---------|
| `app/Http/Controllers/` | Inertia controllers — one per page, thin |
| `app/Services/` | Raw-SQL service classes (all the heavy queries) |
| `app/Models/` | Eloquent models for existing tables (used for the narrow writes) |
| `app/Console/Commands/` | `user:create`, `dna:enqueue`, `dna:backfill-phonetic` |
| `app/Support/Format.php` | Display helpers — createdDate, years, displayLabel, effectiveGender |
| `app/Support/PhoneticEncoder.php` | Metaphone encoder; mirrors `treelib::phonetic_encode` |
| `resources/js/Pages/` | Vue 3 page components |
| `resources/js/Components/App/` | Shared UI — PageHeader, Pagination, ClusterPill, dialogs |
| `resources/css/app.css` | Design system — paper/ink/sepia/wine palette, ref-table, stamp, etc. |
| `deploy/` | Deploy script, nginx configs, systemd units, one-shot SQL |

### Artisan commands

```bash
php artisan user:create <email> [name] [--password=] [--update]
php artisan dna:enqueue <sampleId...> [--priority=10]   # queue match-of-match loading
php artisan dna:backfill-phonetic [--where-null]        # rebuild the *_phonetic columns
```

## Production deploy (Linode VPS, Ubuntu/Debian)

One-time setup:

```bash
sudo apt update && sudo apt install -y \
  nginx php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-intl unzip git curl

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 (NodeSource)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# Clone (use your private repo)
sudo mkdir -p /var/www && sudo chown $USER:$USER /var/www
git clone git@github.com:USER/REPO.git /var/www/dnaweb
cd /var/www/dnaweb
cp .env.example .env
# Edit .env: APP_URL, DB credentials, ADMIN_*, then `php artisan key:generate`

composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed
php artisan storage:link

sudo chown -R www-data:www-data storage bootstrap/cache

# nginx
sudo cp deploy/nginx.conf.template /etc/nginx/sites-available/dnaweb
sudo $EDITOR /etc/nginx/sites-available/dnaweb   # set ${SERVER_NAME} and ${APP_ROOT}
sudo ln -s /etc/nginx/sites-available/dnaweb /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# HTTPS
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d YOURDOMAIN
```

Subsequent deploys:

```bash
cd /var/www/dnaweb && ./deploy/deploy.sh
```

`deploy.sh` pulls, installs, builds, migrates, re-caches config/routes/views and reloads
php8.4-fpm. Run it as a sudo-capable user (e.g. `damo`) — it uses `sudo -u dnaweb` internally so
the checkout stays owned by `dnaweb`.

### Database objects (one-shot SQL)

Run once per environment, before or alongside the first deploy — these are not migrations and
`deploy.sh` does not apply them:

```bash
mariadb dnaweb < deploy/dna_matches2.sql              # the matches table itself
mariadb dnaweb < deploy/dna_matches2-assignment.sql   # Ancestry's headline relationship label
mariadb dnaweb < deploy/dna_matches_kinships.sql      # normalised predictedKinships (cM > 200)
mariadb dnaweb < deploy/queue-migration.sql           # turns dna_match2match_loaded into a queue
mariadb dnaweb < deploy/v_pending_match2match.sql     # "still needs loading" view
mariadb dnaweb < deploy/fulltext-name-search.sql      # *_phonetic columns + FULLTEXT indexes
mariadb dnaweb < deploy/tree-colour.sql               # app-owned tree.colour column
```

`dna-sample-notes.sql` creates `dna_sample_notes` and folds the old per-eye `dna_notes` rows
into it — see "Notes are one per sample" below. Run it before deploying the code that reads it.

`fulltext-name-search.sql` documents `my.cnf` prerequisites (`innodb_ft_min_token_size=2`,
`innodb_ft_enable_stopword=OFF`) that require a MariaDB restart, and must be followed by
`php artisan dna:backfill-phonetic`.

### Background services

Each has a documented header in its unit file — read that before installing.

| Unit | What it does |
|------|--------------|
| `match2match-worker@.service` | Template unit; run N instances to drain the match-of-match queue |
| `ancestry-worker.service` | **Retired 2026-09-09** — pushed `dna_notes` rows with `pushreq=1` back to Ancestry. Notes are local-only now; see below |
| `dnaweb-perl-api.service` | The Mojolicious API on `127.0.0.1:8082` (+ `perl-api-nginx.conf`) |
| `fetch-tree-names.service` | Fills `gedcom_tree` names |
| `phonetic-sweep.service` / `.timer` | `dna:backfill-phonetic --where-null` every 5 minutes |

The Perl-side units read DB credentials from `~/.config/ancestry/db.env` (mode 0600), not from
this repo's `.env`.

## Compare-on-Ancestry buttons (`/dna/{id}/matches`)

The actions cell on each row can show up to three "DNA" buttons opening Ancestry's compare page
in a new tab. The compare URL always uses a kit with a **live Ancestry session** as the from-side,
because only such a kit can render the page — this is the `has_session` / `other_managed`
question, *not* `is_eye`. A kit that is still an eye but has lost its session stays fully
browsable here; it just cannot be a compare from-side.

Below, A is the page's sample, B is the row, C is the eye picked in the "in common with" filter.

| sample (A) | row (B) | filter (C) | Compare buttons rendered |
|------------|---------|------------|--------------------------|
| has session | any        | none  | A↔B                      |
| has session | any        | C set | A↔B, C↔B                 |
| no session  | no session | none  | (none)                   |
| no session  | no session | C set | C↔B                      |
| no session  | has session| none  | B↔A                      |
| no session  | has session| C set | B↔A, C↔B                 |

When C == A the two buttons would produce the same URL, so only one is rendered. Tooltips
disambiguate when multiple buttons appear in the same cell. The page header carries its own
C↔A compare button when an eye is selected.

## Ancestry profile (USER / ADMIN) buttons

Every sample row across the app renders up to two extra Ancestry buttons next to the home/DNA
action buttons:

- **USER** — visible when `dna_samples.userUUID` is non-null; links to
  `https://www.ancestry.com.au/profile/{userUUID}`.
- **ADMIN** — visible when `dna_samples.adminid` is non-null AND the admin row has its own
  `userUUID`; links to the admin's profile. The admin lookup is single-hop — if the admin sample
  itself has an `adminid`, that's ignored.

Practically: a self-managed kit shows only USER; a kit managed by someone else shows only ADMIN.
The two never overlap because a sample either ran its own session (userUUID set, adminid null) or
was loaded under someone else's (adminid set, userUUID null).

## Coexistence with the Perl loaders

The Perl scripts write to `dna_samples`, `dna_matches2`, `people`, `tree*`, `gedcom_*` etc.
continuously. The web app's writes are deliberately scoped to columns and tables the loaders do
NOT overwrite:

| Target | Written by | Notes |
|--------|-----------|-------|
| `dna_sample_notes` (insert/update/delete) | `DnaNoteController` | One note per sample, local-only, app-owned outright. An empty note deletes the row. `dna_notes` is frozen. |
| `people` — `fullName`, `dnaSampleId`, `gender`, `minBirth`, `maxBirth`, `death`, `notes` | `PersonController` | The allow-list is `Person::$fillable`; loader columns (`treetop`, `ddna`, `nogedcom`, `father`, `mother`, `alt`) are excluded. |
| `gedcom_people.peopleid` | `PersonController` | Links a tree node to a person; clears any other node in the same tree first. |
| `tree` (find-or-create by name, `colour`) and `tree_people` membership | `TreeController` | Trees are never deleted here, even when emptied — the loaders own tree lifecycle. |
| `dna_samples.displayName_phonetic`, `people.fullName_phonetic` | Eloquent setters + `dna:backfill-phonetic` | App-owned, mirrored by the Perl encoder. |
| `dna_samples.paternalClusterOverride` | `EyesController::updateParentSide` | App-owned; our own answer to which cluster is paternal. Ancestry's `paternalCluster` is never written. |
| `dna_match2match_loaded` queue state | `DnaSampleService` | Enqueue on page view (priority 10) and the RELOAD button. |
| `dna_origins_loaded` (via `sp_origins_enqueue`), `dna_matches2.originsLoaded` | `OriginsService` | Enqueue on page view; RELOAD resets the eye walk. |

Never let the web app touch `sharedCentimorgans`, `numSharedSegments`, `meiosis`, GEDCOM data
beyond the `peopleid` link above, or create/delete `dna_samples` rows.

Note `dna_matches2` is the current matches table — directional, two rows per pair, where
`sample1` is the viewer and carries that viewer's `matchClusterCode` / `predictedKinships`. The
older `dna_matches` is legacy and unused by this app.

### Notes are one per sample (`dna_sample_notes`)

`dna_notes` is keyed `(sample, mgmtsample)` because that is how Ancestry stores tag 3: a note
belongs to the *eye* that wrote it, so the same person can carry a different note through every
kit you look from. 1,643 of the 21,354 noted samples had notes from more than one eye, up to 10.
The app had to choose a "notes eye" before it could show or edit anything, which is why notes
were invisible on a non-eye sample with no eye selected.

Since 2026-09-09 the app owns `dna_sample_notes` — `sample` PK, `notes` TEXT — one row per DNA
sample, no eye anywhere. `deploy/dna-sample-notes.sql` creates it and merges the old rows: notes
whose text is contained in a longer note for the same sample are dropped as duplicates (which
resolves 265 of the 1,643 on its own), and what survives is concatenated oldest first, each block
headed by the eye that wrote it — 1,378 ended up that way. Run 2026-09-09: 21,353 rows, longest
3,354 chars. Its header comment carries the exact rule and the measured counts.

`dna_notes` is left in place, frozen — `load-dna.pl` no longer fills it, the push-back worker
that drained `pushreq` is retired, and it stays as the record of what came from Ancestry and the
rollback path for the merge. `App\Models\DnaNote` is kept for the same reason; nothing reads it.

The note editor is a plain per-sample panel now, always available (it used to be hidden without
an eye), and accepts 10,000 characters against the old 1,000 — the longest merged note is 3,354,
which the old `varchar(1000)` would have truncated on its first edit.

### Notes are local-only (the retired `pushreq` push-back)

`dna_notes.pushreq` used to mean "this note has not been written back to Ancestry yet".
`worker-ancestry.pl` (`ancestry-worker.service`) drained those rows, POSTing each note to
`discoveryui-matches/parents/list/api/tags/<eye>/match/<match>/tag/3` and clearing the flag on
`updateWasSuccessful`. It was the only reader of the column, and the only place this system ever
wrote to Ancestry.

Retired 2026-09-09: several kits were never set up to receive notes, so the round trip only ever
worked for some of them, and it is no longer wanted. `DnaNoteController` writes `pushreq=0`, the
unit is disabled (`sudo systemctl disable --now ancestry-worker.service` on the loader host), and
the unit file and worker script are kept with a dated header rather than deleted — they are the
only worked example of an authenticated write to Ancestry, and re-enabling means restoring
`pushreq=1` in `DnaNoteController`.

Notes written here are therefore visible in this app only, and since the move to
`dna_sample_notes` above, Ancestry-side notes no longer arrive in the other direction either —
`load-dna.pl` has stopped filling `dna_notes` from the match list. Notes are entirely ours now.

### Kits disabled in Ancestry (`dna_samples.disabled = 1`)

`disabled` means Ancestry has taken the kit away: it can no longer be queried and no more data
will ever arrive for it. It does **not** mean the kit is gone from here. Everything already
loaded about it stays valid, stays listed on every other sample's match page, and its own
`/dna/{id}/matches` and `/dna/{id}/origins` pages render normally.

What changes is only the "ask Ancestry for more" half:

- `DnaSampleService::get()` returns the row (it used to filter `disabled = 0`, which 404'd the
  page while the same sample stayed listed everywhere else) and sets `disabled` plus
  `has_session = false` — a disabled kit can never be a compare from-side, whatever `managed`
  still says.
- `loadingStatus()` short-circuits to state `disabled`. Without it the page spins forever: the
  eyes that matched the kit are still live, so their never-queued pairs read as outstanding work
  that `v_pending_match2match` (which joins `disabled = 0` at *both* ends) will never surface.
- Both matches and origins skip the on-view enqueue, hide the RELOAD / Re-check button, and
  refuse the requeue POST with a 403. `requeueAll()` also joins the target on `disabled = 0` as
  a backstop.
- The page carries a banner saying the data may be incomplete; search results badge the row.

App-owned edits — notes, person records, tree membership — stay available, since none of them
talk to Ancestry at read time.

### ParentSide, and the p1 / p2 override

A match's ParentSide pill is not a stored label — it is derived. `dna_matches2.matchClusterCode`
puts each match in one of the viewer's two parent clusters (`p1` / `p2`), and
`dna_samples.paternalCluster` names which of those two is the paternal one. Only together do they
make a side.

Ancestry fills `paternalCluster` from `discoveryui-matches/cluster/api/paternalCluster/<uuid>`,
and `load-dna.pl` writes it only when that call returns something — which it does not until the
kit's owner has labelled their own sides in Ancestry. 24 of our eyes have never had it set, so
their pills fall back to showing the raw cluster code, `P1` / `P2`. (Ancestry is consistent about
this: for those kits it also puts the literal strings `P1` / `P2` into `dna_matches2.parentSide`
where a labelled kit gets `PATERNAL` / `MATERNAL`, and both readers treat those two as
non-authoritative and fall through to the cluster code.)

`dna_samples.paternalClusterOverride` (`deploy/paternal-cluster-override.sql`) is our own answer
to the same question, and it **wins** over Ancestry's — it covers both "the owner never said" and
"the owner said, and got it backwards". It is edited on `/eyes`, in the ParentSide mapping column;
the editor shows the strongest matches in each cluster alongside their predicted kinships, since
recognising a close relative is how you actually decide which side is which.

Readers never touch either column directly. `App\Support\Sql::effectivePaternalCluster()` resolves
the pair and aliases the result back to `paternalCluster`, so the two places that turn a cluster
code into a side — `ClusterPill.vue` and `DnaSampleService::parentSideFilter()` — need no
knowledge of the override, and the pills and the ParentSide filter dropdown cannot disagree.

## Status

- **Phase 0** (done) — Laravel scaffold, Breeze auth, MariaDB connection
- **Phase 1** (done) — read-only feature parity with the Django pages, redesigned UI
- **Phase 2** (done) — `dna_notes` CRUD. The `pushreq` push-back it shipped with was retired on
  2026-09-09 (notes are local-only now). The originally planned
  `dna_matches2.ignored` toggle and `matchClusterCode` editing are **not** implemented; the
  columns are read-only in the UI today.
- **Phase 3** (done) — tree visualisation (`/person/{id}/tree`, `family-chart`), with ancestor /
  descendant depth steppers
- **Phase 4** (done) — VPS deploy (this README + `deploy/`)
- **Phase 5** (in progress) — broader writes. People edit/create and tree membership have
  landed; parent links (`father`/`mother`), `alt` disambiguation for duplicate names, and the
  Phase 2 match-curation columns are still outstanding.

Also shipped since the original plan: FULLTEXT lexical + phonetic name search, the match-of-match
queue and its workers, the origins (ethnicity) page and its eye-walk loader, the Mojolicious Perl
API, and the public `/dna-share` instructions page.
