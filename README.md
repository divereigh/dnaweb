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

`fulltext-name-search.sql` documents `my.cnf` prerequisites (`innodb_ft_min_token_size=2`,
`innodb_ft_enable_stopword=OFF`) that require a MariaDB restart, and must be followed by
`php artisan dna:backfill-phonetic`.

### Background services

Each has a documented header in its unit file — read that before installing.

| Unit | What it does |
|------|--------------|
| `match2match-worker@.service` | Template unit; run N instances to drain the match-of-match queue |
| `ancestry-worker.service` | Pushes `dna_notes` rows with `pushreq=1` back to Ancestry |
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
| `dna_notes` (insert/update/delete) | `DnaNoteController` | Always sets `pushreq=1` so `ancestry-worker` pushes the note back to Ancestry. An empty note deletes the row. |
| `people` — `fullName`, `dnaSampleId`, `gender`, `minBirth`, `maxBirth`, `death`, `notes` | `PersonController` | The allow-list is `Person::$fillable`; loader columns (`treetop`, `ddna`, `nogedcom`, `father`, `mother`, `alt`) are excluded. |
| `gedcom_people.peopleid` | `PersonController` | Links a tree node to a person; clears any other node in the same tree first. |
| `tree` (find-or-create by name, `colour`) and `tree_people` membership | `TreeController` | Trees are never deleted here, even when emptied — the loaders own tree lifecycle. |
| `dna_samples.displayName_phonetic`, `people.fullName_phonetic` | Eloquent setters + `dna:backfill-phonetic` | App-owned, mirrored by the Perl encoder. |
| `dna_match2match_loaded` queue state | `DnaSampleService` | Enqueue on page view (priority 10) and the RELOAD button. |
| `dna_origins_loaded` (via `sp_origins_enqueue`), `dna_matches2.originsLoaded` | `OriginsService` | Enqueue on page view; RELOAD resets the eye walk. |

Never let the web app touch `sharedCentimorgans`, `numSharedSegments`, `meiosis`, GEDCOM data
beyond the `peopleid` link above, or create/delete `dna_samples` rows.

Note `dna_matches2` is the current matches table — directional, two rows per pair, where
`sample1` is the viewer and carries that viewer's `matchClusterCode` / `predictedKinships`. The
older `dna_matches` is legacy and unused by this app.

## Status

- **Phase 0** (done) — Laravel scaffold, Breeze auth, MariaDB connection
- **Phase 1** (done) — read-only feature parity with the Django pages, redesigned UI
- **Phase 2** (done) — `dna_notes` CRUD with `pushreq` push-back. The originally planned
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
