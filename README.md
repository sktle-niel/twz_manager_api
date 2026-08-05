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
| `PUT /api/account/password` | done — both roles change their own, current password required |
| `PUT /api/managers/{id}/password` | done (owner only) — recovery, behind the PIN |
| `GET /api/settings/reset-pin` | done (owner only) — whether the PIN is still the shipped one |
| `PUT /api/settings/reset-pin` | done (owner only) — change it |
| `GET /api/stores` | done (auth required) — real branches, synced from Loyverse |
| `GET /api/settings/pos` | done (owner only) — connection status, linked-store count, token hint |
| `POST /api/settings/pos/reconnect` | done (owner only) — live token validation with human answers |
| `GET /api/sales/daily`, `GET /api/sales/hourly` | done — real Loyverse receipts, gross + gross profit |
| `GET /api/audits`, `GET /api/deposits`, `GET /api/deposits/pending` | done — the audit spine, deposits read-only |
| `GET/POST/PATCH/DELETE /api/expenses`, `GET/PUT /api/expense-categories` | done — with receipt photos |
| `GET /api/accounts/{id}/sign-ins` | done — recorded at login, "this device" by session |
| `GET/PATCH /api/settings/reconciliation` | done — read by both roles, written by the owner |
| `GET /api/files/{path}` | done — stored photos, behind the session cookie |
| still missing | `POST /api/deposits` (record with slip), managers CRUD, `PATCH /api/account`, search |

Error bodies follow the contract: `{ message, fields? }`, one human-readable message per
field. A 401 from any endpoint drops the frontend to its sign-in screen.

**Rate limits, both directions:**

- *Inbound* — the api group throttles at 120 requests/minute per account (per IP before
  sign-in), and sign-in itself pauses for a minute after five failures per identifier+IP;
  only failures count, and a success clears the slate. The recovery PIN is tighter still —
  five wrong tries and it stops answering that owner for fifteen minutes, because four
  digits is ten thousand guesses. The group-wide limiter only runs because `bootstrap/app.php` opts in with
  `throttleApi()`; Laravel no longer includes `throttle:api` by default, so defining a
  limiter is not the same as applying one. `tests/Feature/Api/RateLimitTest.php` fails if
  that line ever goes missing.
- *Outbound* — every Loyverse request goes through `App\Services\Loyverse\LoyverseClient`,
  which counts against a cache-backed budget (`LOYVERSE_RATE_BUDGET`, default 240 of the
  account's 300-per-300-seconds) *before* the request leaves, and treats a 429 from
  Loyverse as the same condition with a Retry-After. Callers get
  `LoyverseBudgetExhausted` and reschedule — nothing ever spins against the merchant's
  shared budget.

**No email anywhere.** Accounts have no email address: nobody signs in with one, nothing is
mailed to one, and the column is gone. It was a field somebody had to invent a value for and
nothing ever read.

**Recovery** therefore runs through the owner. A manager who is locked out asks in person, and the
owner sets a new password from the Managers page — `PUT /api/managers/{id}/password`, no old
password needed, because the whole point is that nobody has it. Two locks guard it: being signed in
as the owner, and a 4-digit PIN. Neither alone is enough, so an unattended laptop still signed in
cannot be used to take a branch account. Setting a password also rotates `remember_token`, which
signs out every device that was still holding one.

**The PIN** starts as `ADMIN_RESET_PIN` in `.env` (`8017` out of the box) and moves to the database
the moment the owner changes it, hashed — from then on the `.env` value is never read again. It
lives in two places on purpose: a fresh install needs a known first PIN, and a web request cannot
rewrite `.env` (nor would a cached config notice if it did). `GET /api/settings/reset-pin` reports
whether it is still the shipped value — including when somebody sets it back to that — because a
default written down in a repo is not a secret. **Change it before the first deploy.**

**When the owner is the one locked out**, there is nobody above them and no inbox to mail. The way
back is `php artisan twz:set-password <username>` over SSH, deliberately outside the API: reaching
the server at all is already proof of who you are.

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

**Branches are real, not seeded.** `php artisan twz:sync-stores` pulls the store list from
Loyverse and links it to ours by name (slugified both sides, so "La Paz" finds `lapaz`).
Our ids stay ours — `loyverse_store_id` is the only bridge — a Loyverse store nothing matches
becomes a new branch, and a local branch Loyverse does not know is reported, never deleted.
Run it at setup and whenever a branch opens or closes. The four invented branches
(Arevalo/Molo/Jaro/La Paz) now exist only inside the test suite.

Seeded accounts:

| Username | Role | Password |
|---|---|---|
| `twowheelszone` | owner | `OWNER_PASSWORD` in `.env` — the *first* sign-in only |
| `marvin.deocampo` | manager, Arevalo | `password` |
| `joel.sarabia` | manager, Molo | `password` |
| `rhea.villanueva` | manager, Jaro | `password` |
| `testaccount` | manager, La Paz | `test1234` |

The owner's password is seeded exactly once, when no owner exists yet; change it on the
Account page and it lives in the database from then on — reseeding never puts the `.env`
value back (`tests/Feature/SeededOwnerTest.php` holds that promise). The dev accounts do
reset to the passwords above on every seed, and they are skipped entirely in production.
The recovery PIN on a fresh database is `8017`.

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
   `APP_URL=https://your-domain`, `SESSION_SECURE_COOKIE=true`, and the owner's first
   credentials — `OWNER_USERNAME` and a strong, never-committed `OWNER_PASSWORD`.
4. **First boot**: `php artisan migrate --force`, `php artisan db:seed --force` (outside
   the test suite this creates only the owner), `php artisan twz:sync-stores` (pulls the
   real branches from Loyverse), then `php artisan config:cache`,
   `php artisan route:cache`, `php artisan view:cache` (via SSH).
5. **Change the PIN**: `ADMIN_RESET_PIN` ships as `8017` and is written down in this repo.
   Set a different one in `.env` before the first sign-in, or change it in Settings straight
   after — the app warns while it is still the shipped value.
6. **Cron**: one hPanel cron entry runs everything scheduled, including the future Loyverse
   sync: `* * * * * php /path/to/app/artisan schedule:run`.
7. **Secrets**: the Loyverse token goes in `.env` on the server only — it has no scopes and
   grants full merchant access. It must never appear in the repo or the frontend.
