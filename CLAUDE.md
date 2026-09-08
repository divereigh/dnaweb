# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Laravel 12 + Inertia 2 + Vue 3 read-mostly viewer/editor over the `dnaweb` MariaDB database
of Ancestry DNA data. It is one of three cooperating pieces:

| Piece | Location | Role |
|---|---|---|
| This app | `/home/damo/dnaweb` | Web UI. Reads everything, writes a deliberately narrow set of columns. |
| Perl loaders + workers | `~/ancestry/program` (dev box), `/home/damo/ancestry-program` (production) | Own the schema. Fetch from Ancestry, fill the data tables, drain the queues. |
| Perl API (Mojolicious) | `.../perl-api`, port 8082 | Sync HTTP wrapper over `treelib`, called from `App\Services\PerlApi`. |

The schema is **not** owned here. Never write a Laravel migration against a data table
(`dna_samples`, `dna_matches2`, `people`, `tree*`, `gedcom_*`, …) — `database/migrations/`
holds only Laravel's own users/cache/jobs tables. Data-table DDL lives as hand-run `.sql`
files in `deploy/` (`dna_matches2.sql`, `fulltext-name-search.sql`, `v_pending_match2match.sql`,
`tree-colour.sql`, …), applied once per environment with `mariadb dnaweb < deploy/<file>.sql`.

## Commands

```bash
composer dev          # server:8765 + queue + pail logs + vite, all in one (preferred)
php artisan serve --port=8765   # port 8000 is taken on the dev box
npm run dev           # vite hot reload
npm run build         # production assets

composer test         # config:clear then artisan test
php artisan test --filter=AuthenticationTest        # one test class
php artisan test tests/Feature/ProfileTest.php      # one file
./vendor/bin/pint     # formatter (Laravel defaults, no pint.json)

php artisan user:create <email> [name] [--password=] [--update]
php artisan dna:enqueue <sampleId...> [--priority=10]   # queue match-of-match loading
php artisan dna:backfill-phonetic [--where-null]        # rebuild *_phonetic columns
```

Tests run against sqlite `:memory:` (see `phpunit.xml`), so they cannot touch the real
schema — the current suite is Breeze's auth/profile defaults only. Anything exercising a
service class needs the MariaDB connection, so verify service changes by hitting the page.

Dev DB is on `[::1]:3308` (SSH tunnel to production); `PERL_API_URL` is likewise a tunnel to
production's `127.0.0.1:8082`. Both can be down — `PerlApi` returns `null` on any failure and
callers are expected to degrade rather than error.

Deploy: `./deploy/deploy.sh` on the VPS (git pull → composer → npm build → migrate → recache →
reload php8.4-fpm). Run it as a sudo-capable user, not as `dnaweb`.

## Writes are scoped on purpose

The Perl loaders continuously overwrite most columns. This app only writes columns they never
touch:

- `dna_notes.notes` (full CRUD; deleting the row is how "no notes" is stored, and every write
  sets `pushreq=1` so the loader pushes it back to Ancestry)
- `dna_matches2.ignored`, `dna_matches2.matchClusterCode` — user curation
- `dna_samples.paternalClusterOverride` — our own p1/p2 → paternal mapping, which wins over
  Ancestry's `paternalCluster` (never written here). Edited on `/eyes`; resolved by
  `App\Support\Sql::effectivePaternalCluster()`, see `deploy/paternal-cluster-override.sql`
- `people` rows via `Person::$fillable` — loader-managed columns (`treetop`, `ddna`, `nogedcom`,
  `father`, `mother`, `alt`) are excluded
- tree membership (`tree_people`) and `tree` colour/label
- queue rows in `dna_match2match_loaded` / `dna_origins_loaded` (status flips only)

Never write `sharedCentimorgans`, `numSharedSegments`, `meiosis`, GEDCOM tables, or create/delete
`dna_samples` rows.

## Architecture

**Controllers are thin, services hold raw SQL.** Every page is one controller in
`app/Http/Controllers/` that injects one or more `app/Services/` classes and returns
`Inertia::render()`. The services (`DnaSampleService` is the big one, ~700 lines) run hand-written
`DB::select()` against a schema Eloquent doesn't model well; Eloquent models exist mainly for
the handful of writes and for the phonetic-column accessors. Row arrays are decorated in PHP with
`App\Support\Format` helpers (`display_label`, `created_fmt`, `effective_gender`) so Vue never
recomputes display logic.

**`dna_matches2` is the matches table.** Directional — two rows per pair, `sample1` is the viewer
and carries that viewer's `matchClusterCode` / `predictedKinships`. The older `dna_matches` is
legacy. `dna_matches_kinships` is the normalised, joinable form of `predictedKinships` for
cM > 200, resolved to labels by `KinshipLabelService` against the small `relationships` table
(gender-specific rows plus a NULL-gender combined row; the label depends on the *other* party's
effective gender).

**"Eye" is the central domain concept** — a kit the loaders have run as. `EyeSetService` is the
single answer to "is this an eye", and its class docblock is required reading: eye-ness
(`mgmtsample` on the loader work queues) and "has a live Ancestry session" (`dna_samples.managed`
→ `session.id`) are different questions. Conflating them once hid six kits and ~90k matches.
Anything asking *can a fetch still happen* joins `session` on `managed` instead —
`DnaSampleService::requeueAll()` is the pattern to copy. `sqlIn()` inlines the ~114 ids into SQL
deliberately, because the predicate is evaluated per row on lists tens of thousands of rows long.

**Loading is queue-driven, and the UI drives the queue.** Visiting `/dna/{id}/matches` calls
`enqueueForSample()` (idempotent, priority 10) so the sample the user is looking at jumps ahead
of the bulk sweeps; the page then polls `loading_in_progress` and shows a spinner until the
`match2match-worker@N` systemd instances drain it. `v_pending_match2match` is the view that
defines "still needs loading". Origins works the same way (`OriginsService`, `worker-origins.pl`,
`v_origins_status`) with the extra wrinkle that a "shared only" kit reveals a different slice of
its ethnicity to each eye, so the loader walks the eyes accumulating a union — "complete" means
100% held *or* every eye asked.

**Inertia partial reloads are load-bearing.** Polling and search-debounce use
`router.reload({ only: [...], preserveState, preserveScroll })`, and controllers check
`X-Inertia-Partial-Data` to skip the enqueue and the expensive closures on those requests —
without it a 10s poll was costing ~350ms of DB work per tick. Props that are expensive to build
must be passed as closures so Inertia can skip them.

**Name search is lexical + phonetic FULLTEXT.** `PhoneticEncoder` (PHP) must stay byte-identical
to `treelib::phonetic_encode` (Perl); both fill `dna_samples.displayName_phonetic` and
`people.fullName_phonetic`. Eloquent `Attribute` setters on `DnaSample::displayName` /
`Person::fullName` keep them in sync — **raw `DB::update()` bypasses this**, which is why the
nightly `dna:backfill-phonetic --where-null` sweep exists. Queries score raw matches ×2 over
phonetic (`DnaSampleService::search`).

**Trees are recursive.** `FamilyTreeService::build()` walks `people.father`/`mother` up 6 and down
4 generations and emits the f3 (`family-chart`) shape with string ids.

## Frontend

Pages in `resources/js/Pages/`, shared UI in `resources/js/Components/App/`. Ziggy `route()` is
available globally. `resources/css/app.css` is a small hand-rolled design system on top of
Tailwind — use the existing component classes (`.card`, `.ref-table`, `.ref-link`, `.filter-bar`,
`.stamp`, `.pg-tile`, `.btn-primary`, `.btn-ghost`, `.eyebrow`, `.sortable`) and the `paper` /
`ink` / `sepia` / `wine` / `marine` colour scales rather than inventing new ones. Numeric cells
get `data-numeric` for tabular figures.

`Dna/Matches.vue` (~1000 lines) is the app's centre of gravity: eye picker, parent-side filter,
tree include/exclude, search, note editing, and the Ancestry compare buttons. The README documents
the compare-button and USER/ADMIN profile-button rules as truth tables — read those before
touching the actions cell, since the rules are asymmetric and non-obvious.

## Conventions worth keeping

- Long comments explaining *why* a query is shaped the way it is, especially where a MariaDB
  quirk or a past bug forced it. Several of these encode incidents; don't strip them when
  refactoring.
- Routes that are retired get commented out in `routes/web.php` with a date and reason rather
  than deleted, with controllers/services/pages left in place (see `eyes.matches` / `common.index`).
- `config/admin.php` reads `ADMIN_*` at config-load time so it survives `config:cache`; use
  `config()` not `env()` outside of `config/`.
