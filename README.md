# DNAWeb

Laravel 12 + Inertia + Vue 3 viewer/editor for the `dnaweb` MariaDB database. Replaces the earlier Django app (archived at `~/dnaweb-django-backup`).

The MariaDB schema is owned by Perl loader scripts in `~/ancestry/program`; this app must NOT run schema migrations against the data tables.

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
php artisan serve --port=8765  # port 8000 is taken on the dev box
npm run dev                    # in another terminal — Vite hot reload
```

Login at `http://127.0.0.1:8765/login` with the email/password from `.env`.

## Project layout

| Path | Purpose |
|------|---------|
| `app/Http/Controllers/` | Inertia controllers — one per page |
| `app/Services/` | Raw-SQL service classes (heavy queries) |
| `app/Models/` | Eloquent models for existing tables (read-only schema) |
| `app/Support/Format.php` | Display helpers — createdDate, clusterClass, years, displayLabel |
| `resources/js/Pages/` | Vue 3 page components |
| `resources/js/Components/App/` | Shared UI — PageHeader, Pagination, ClusterPill |
| `resources/css/app.css` | Design system — paper palette, ref-table, stamp, etc. |
| `deploy/` | Deployment scripts and nginx config template |

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

## Phases

- **Phase 0** (done) — Laravel scaffold, Breeze auth, MariaDB connection
- **Phase 1** (done) — read-only feature parity for the seven Django pages, redesigned UI
- **Phase 2** (next) — narrow writes: edit `dna_notes.notes`, toggle `dna_matches.ignored`, set `matchClusterCode`
- **Phase 3** — tree visualisation (ancestors, descendants, GEDCOM, DNA overlay) with vue-flow
- **Phase 4** — VPS deploy (this README + `deploy/`)
- **Phase 5** — broader writes (people, tree memberships)

Plan: `~/.claude/plans/i-have-two-folders-eager-horizon.md`.

## Compare-on-Ancestry buttons (`/dna/{id}/matches`)

The actions cell on each row can show up to three "DNA" buttons opening Ancestry's compare page in a new tab. Which appear depends on whether the page's sample (A) is a managed eye, whether the row (B) is, and whether an eye (C) is picked from the "in common with" filter. The compare URL always uses an eye as the from-side because only an eye has the Ancestry session needed to render the page.

| sample (A) | row (B) | filter (C) | Compare buttons rendered |
|------------|---------|------------|--------------------------|
| eye        | any     | none       | A↔B                      |
| eye        | any     | C set      | A↔B, C↔B                 |
| not eye    | not eye | none       | (none)                   |
| not eye    | not eye | C set      | C↔B                      |
| not eye    | eye     | none       | B↔A                      |
| not eye    | eye     | C set      | B↔A, C↔B                 |

When A is an eye and C == A, the C↔B duplicate is suppressed. Tooltips disambiguate when multiple buttons appear in the same cell.

## Coexistence with the Perl loaders

The Perl scripts at `~/ancestry/program` write to `dna_samples`, `dna_matches`, `people`, etc., continuously. The web app's writes are deliberately scoped to columns the loaders do NOT overwrite:

- `dna_notes.notes` (full CRUD; loaders don't write notes)
- `dna_matches.ignored` (user curation only)
- `dna_matches.matchClusterCode` (user curation only)

Phase 5 may relax this. Until then, never let the web app touch `sharedCentimorgans`, `numSharedSegments`, `meiosis`, GEDCOM tables, or `dna_samples` rows.
