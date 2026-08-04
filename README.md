# TWZ Manager API

The backend for **TWZ Manager** — daily sales audit and bank-deposit reconciliation for Two
Wheels Zone. The frontend repo's `docs/API.md` is the endpoint-by-endpoint contract this app
implements, and its `docs/LOYVERSE.md` is the POS integration study this app will execute.

## Stack, and why

**Laravel 13 · PHP 8.3 · MySQL (SQLite in development).**

Chosen for one hard constraint: it must run on Hostinger, on any plan. Hostinger's shared
hosting is PHP-first — PHP version per domain in hPanel, MySQL with phpMyAdmin, Composer on
the servers, SSH, Git deploy, and cron jobs — while Node.js apps require a Business/Cloud
plan. Laravel also happens to fit the contract exactly: the httpOnly session cookie in
`docs/API.md` is Laravel's session guard, multipart uploads and file storage are built in, and
the Loyverse sync will ride the scheduler through a single cron entry.

`composer.json` pins the platform to PHP **8.3** so dependency resolution can never drift
past what Hostinger runs, even when local PHP is newer.

## Status

**Identity slice implemented and verified end-to-end against the real frontend:**

| Endpoint | State |
|---|---|
| `GET /api/session` | done — anonymous answers `{manager: null, owner: null}`, never 401 |
| `POST /api/session` | done — username or Gmail, case-insensitive, `remember` honoured |
| `DELETE /api/session` | done |
| `POST /api/password-resets` | accepts and 204s; the reset email itself awaits mail config |
| `GET /api/stores` | done (auth required) |
| everything else in `docs/API.md` | not yet — sales, expenses, audits, deposits, settings |

Error bodies follow the contract: `{ message, fields? }`, one human-readable message per
field. A 401 from any endpoint drops the frontend to its sign-in screen.

**CSRF stance:** the session cookie is `SameSite=Lax` and every write verifies the `Origin`
header against the app's own origin plus `FRONTEND_ORIGINS`
(`app/Http/Middleware/VerifyOriginOnUnsafeRequests.php`). Laravel's token CSRF is deliberately
not used — the frontend contract promises plain requests.

## Running it locally

Needs PHP 8.3+ and Composer (both on PATH after setup).

```bash
composer install
cp .env.example .env      # then: php artisan key:generate
php artisan migrate --seed
php artisan serve         # http://localhost:8000
```

Seeded accounts — password `password` for all of them:

| Account | Role |
|---|---|
| `twz.owner` / owner@gmail.com | owner |
| `marvin.deocampo` | manager, Arevalo |
| `joel.sarabia` | manager, Molo |
| `rhea.villanueva` | manager, Jaro |

### Pairing with the frontend

The frontend dev server proxies `/api` to `localhost:8000` (see its `vite.config.ts`), so
cookies stay same-origin exactly like production. In the frontend:

```bash
VITE_DATA_SOURCE=http npm run dev   # real backend instead of sample data
```

## Deploying to Hostinger

1. **PHP version**: set the domain to PHP 8.3 in hPanel → PHP Configuration.
2. **Code**: deploy with hPanel's Git integration. The app must live *outside* the web root;
   only `public/` is served — point the domain's document root at it (or deploy beside
   `public_html` and make `public_html` a symlink to `public/`).
3. **Database**: create a MySQL database + user in hPanel, then in `.env`:
   `DB_CONNECTION=mysql` with the credentials, `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL=https://your-domain`, `SESSION_SECURE_COOKIE=true`.
4. **First boot**: `php artisan migrate --force`, `php artisan config:cache`,
   `php artisan route:cache` (via SSH).
5. **Cron**: one hPanel cron entry runs everything scheduled, including the future Loyverse
   sync: `* * * * * php /path/to/app/artisan schedule:run`.
6. **Secrets**: the Loyverse token goes in `.env` on the server only — it has no scopes and
   grants full merchant access. It must never appear in the repo or the frontend.
