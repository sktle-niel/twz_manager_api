# Deploying to Hostinger

One origin serves everything: this Laravel app answers `/api/*`, and every
other path returns the staged PWA build (`public/app.html`). Same-origin
means the session cookie just works — no CORS, no SameSite tuning.

Two ways in. **Method A** needs only the hPanel File Manager and a browser —
no SSH, no composer on the server. **Method B** (further down) is the
git + SSH flow.

## Method A — File Manager only

**Build the package on the dev machine** (already scripted pieces):

1. In the frontend repo: `npm run stage` — builds and copies the PWA into
   this repo's `public/`.
2. Copy this whole repo (INCLUDING `vendor/`, EXCLUDING `.git`, `tests`,
   `storage/logs`, `storage/framework/{cache/data,sessions,views}` contents)
   into a folder named `app`, replace `.env` with a production one (APP_KEY
   generated locally, DB placeholders, the Loyverse token, the VAPID keys,
   and a random `SETUP_KEY`), and zip the `app` folder.

**On Hostinger:**

3. hPanel → the website → **Advanced → PHP Configuration** → PHP **8.3**.
4. **Databases → Management** → create database + user + password.
5. **Files → File Manager** → open `domains/YOURDOMAIN.com/` → upload the
   zip → right-click → **Extract**. You now have `…/app/` with `public/`
   inside.
6. Point the site's **document root** at `domains/YOURDOMAIN.com/app/public`
   (or create a subdomain with that custom folder).
7. In File Manager, open `app/.env` → fill in `APP_URL` and the three `DB_*`
   placeholders → save.
8. In a browser, visit `https://YOURDOMAIN/setup/THE_SETUP_KEY?start=YYYY-MM-DD`
   (the exact URL is written inside `.env` beside `SETUP_KEY`). One visit
   migrates, seeds, sets the ledger's start day, and caches — the JSON
   answer lists what happened.
9. Back in File Manager, **delete the two SETUP_KEY lines** from `.env`.
10. hPanel → **Advanced → Cron Jobs** → one entry:
    `* * * * * /usr/bin/php /home/USERNAME/domains/YOURDOMAIN.com/app/artisan schedule:run >> /dev/null 2>&1`
11. Run the smoke checks at the bottom, then **change every seeded password
    and the reset PIN** inside the app.

**Later updates without SSH:** re-run `npm run stage`, then upload just the
changed pieces through File Manager — `public/app.html` + `public/assets/`
for frontend changes; the changed `app/…` PHP files (plus visiting the setup
URL again if a migration shipped — re-add SETUP_KEY for the visit, delete
after).

## Method B — SSH + git

### One-time setup (hPanel)

1. **PHP version**: set the site to **PHP 8.3** (Websites → PHP configuration).
   Confirm extensions: `openssl`, `pdo_mysql`, `curl`, `mbstring` (all present
   on Hostinger's 8.3 by default).
2. **MySQL**: create a database + user in hPanel, note the credentials.
3. **Domain root**: point the (sub)domain's document root at the app's
   `public/` directory (Websites → the domain → document root). Laravel's
   `.htaccess` in `public/` does the rest under LiteSpeed.
4. **SSH**: enable SSH access (Advanced → SSH) — everything below runs there.

### First deploy

```bash
# 1. Get the code (or upload a zip and unzip)
git clone https://github.com/sktle-niel/twz_manager_api.git app && cd app

# 2. Dependencies — no dev packages on the server
composer install --no-dev --optimize-autoloader
#    If repo.packagist.org times out (it does on some networks):
#      composer config repos.packagist composer https://mirrors.tencent.com/composer/
#      composer install --no-dev --optimize-autoloader
#      composer config --unset repos.packagist

# 3. Environment
cp .env.example .env && php artisan key:generate
```

Fill in `.env` (the checklist that matters):

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://your-domain` |
| `DB_*` | the hPanel MySQL database, user, password |
| `LOYVERSE_API_TOKEN` | the merchant token (full-account scope — guard it) |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` | the push identity — copy from the dev `.env`, or generate a fresh pair (changing keys silently orphans existing subscriptions; devices must re-enable reminders) |

```bash
# 4. Database
php artisan migrate --force

# 5. Accounts and settings — seed, then set real passwords and the reset PIN
php artisan db:seed --force

# 5b. The ledger's start day — the date reconciliation begins. Without it,
#     the 90-day Loyverse backfill would surface three months of "pending
#     deposit" backlog on day one. Set it to the go-live date:
php artisan tinker --execute="App\Models\Setting::write('audit_start_day', '2026-08-13');"

# 6. Cache the boot work (rerun after every deploy)
php artisan config:cache && php artisan route:cache

# 7. Cron — ONE entry drives everything scheduled (sales sync every minute,
#    catalog every 30 min, push reminders hourly). hPanel → Advanced → Cron:
#    * * * * * /usr/bin/php /home/USER/app/artisan schedule:run >> /dev/null 2>&1

# 8. First data pull (the cron would do these too, this just avoids the wait)
php artisan twz:sync-sales && php artisan twz:sync-catalog
```

### Staging the frontend

From the **frontend repo** on the dev machine:

```bash
npm run stage        # builds, then copies dist/ into ../…/twowheelszone-manager-api/public
```

`index.html` lands as **`app.html`** (so it never shadows `index.php`);
`assets/`, icons, `manifest.webmanifest`, and `sw.js` copy as-is. These are
gitignored in this repo — upload `public/app.html` + `public/assets/` + the
icons/manifest/sw.js to the server (scp/SFTP) after every frontend build:

```bash
scp -r public/app.html public/assets public/*.png public/*.webp \
    public/manifest.webmanifest public/sw.js  USER@HOST:~/app/public/
```

### Every later deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
# plus re-upload the staged frontend when it changed
```

## Smoke checks

- `https://your-domain/api/session` → `{"message":"Your session has expired…"}` (JSON, not HTML)
- `https://your-domain/` → the sign-in page
- Sign in, open the dashboard: sales figures appear (Loyverse sync ran)
- `https://your-domain/sw.js` → the service worker source
- Account → Reminders → "Turn on reminders" → next 09:00/19:00 branch time
  delivers (or trigger once by hand: `php artisan twz:send-reminders`)

## Notes

- **Never** put the Loyverse token or VAPID private key anywhere but `.env`.
- Uploaded photos (slips, receipts) live in `storage/app/private/receipts` and
  are served through the authenticated `/api/files/…` route — no public link,
  nothing to expose.
- Web push needs HTTPS; Hostinger's free SSL on the domain is enough.
- The receipts table is a re-buildable copy: clearing it plus the
  `loyverse_receipts_watermark` setting makes the next sync re-pull the whole
  backfill window (the sales-filter save does exactly this on purpose).
