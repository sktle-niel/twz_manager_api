# TWZ Manager API

The backend for **TWZ Manager** — daily sales audit and bank-deposit reconciliation for Two
Wheels Zone. The frontend repo's `docs/API.md` is the endpoint-by-endpoint contract this app
implements, and its `docs/LOYVERSE.md` is the POS integration study this app will execute.

## Stack, and why

**Laravel 13 · PHP 8.3 · MySQL** — Laragon's MySQL 8.4 locally (database
`twz_manager`, browseable in phpMyAdmin), Hostinger's MySQL in production.

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
| `POST /api/password-resets` | done — stores a token, mails the link, 204 either way |
| `POST /api/password-resets/redeem` | done — spends the token, one use, 60-minute life |
| `GET /api/stores` | done (auth required) |
| `GET /api/settings/pos` | done (owner only) — connection status, linked-store count, token hint |
| `POST /api/settings/pos/reconnect` | done (owner only) — live token validation with human answers |
| everything else in `docs/API.md` | not yet — sales, expenses, audits, deposits, reconciliation settings |

Error bodies follow the contract: `{ message, fields? }`, one human-readable message per
field. A 401 from any endpoint drops the frontend to its sign-in screen.

**Rate limits, both directions:**

- *Inbound* — the api group throttles at 120 requests/minute per account (per IP before
  sign-in), and sign-in itself pauses for a minute after five failures per identifier+IP;
  only failures count, and a success clears the slate. Asking for a reset link is tighter
  still — six per hour per IP, because that endpoint sends mail to an address the caller
  names. The group-wide limiter only runs because `bootstrap/app.php` opts in with
  `throttleApi()`; Laravel no longer includes `throttle:api` by default, so defining a
  limiter is not the same as applying one. `tests/Feature/Api/RateLimitTest.php` fails if
  that line ever goes missing.
- *Outbound* — every Loyverse request goes through `App\Services\Loyverse\LoyverseClient`,
  which counts against a cache-backed budget (`LOYVERSE_RATE_BUDGET`, default 240 of the
  account's 300-per-300-seconds) *before* the request leaves, and treats a 429 from
  Loyverse as the same condition with a Retry-After. Callers get
  `LoyverseBudgetExhausted` and reschedule — nothing ever spins against the merchant's
  shared budget.

**Password resets** take two requests. `POST /api/password-resets` accepts a username or Gmail
address and answers `204` whether or not the account exists — the endpoint must never reveal who
has one. Behind that 204, a hashed token goes into `password_reset_tokens` (one row per account,
replaced by any newer request, deleted the moment it is spent) and Laravel mails a link. The link
points at the **frontend**, named by `FRONTEND_URL` — the page that collects a new password belongs
to the PWA, and this API serves no HTML. `POST /api/password-resets/redeem` spends it: the token
travels in the body rather than the URL so it never lands in an access log, it works once, it dies
after sixty minutes, and a fresh `remember_token` signs out any device still holding an old
"remember me" cookie. A disabled account is refused at both ends — silently when asking, and as an
expired link when redeeming.

In development `MAIL_MAILER=log`, so the link lands in `storage/logs/laravel.log` rather than an
inbox. **The frontend still owes this flow a page**: `/reset-password`, reading `token` and `email`
off the query string and posting them back with the new password. Until that exists the mailed link
opens nothing — the backend half is done, the browser half is not.

**The Loyverse token** has no scopes — full read/write over the whole merchant account. It
lives in `.env` (`LOYVERSE_API_TOKEN`) and nowhere else; the API exposes only its last four
characters as `tokenHint`.

**CSRF stance:** the session cookie is `SameSite=Lax` and every write verifies the `Origin`
header against the app's own origin plus `FRONTEND_ORIGINS`
(`app/Http/Middleware/VerifyOriginOnUnsafeRequests.php`). Laravel's token CSRF is deliberately
not used — the frontend contract promises plain requests.

## Running it locally

Needs PHP 8.3+, Composer, and a running MySQL (Laragon's, with the
`twz_manager` database — or set `DB_CONNECTION=sqlite` and skip MySQL).

```bash
composer install
cp .env.example .env      # then: php artisan key:generate
php artisan migrate --seed
php artisan serve         # http://localhost:8000
```

phpMyAdmin over the same database: start Laragon (or serve the bundled copy
directly: `php -S 127.0.0.1:8080 -t C:\laragon\etc\apps\phpmyadmin`), sign in
as `root` with no password.

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
