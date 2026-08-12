# Deploying to Hostinger

One origin serves everything: this Laravel app answers `/api/*`, and every
other path returns the staged PWA build (`public/app.html`). Same-origin
means the session cookie just works — no CORS, no SameSite tuning.

## One-time setup (hPanel)

1. **PHP version**: set the site to **PHP 8.3** (Websites → PHP configuration).
   Confirm extensions: `openssl`, `pdo_mysql`, `curl`, `mbstring` (all present
   on Hostinger's 8.3 by default).
2. **MySQL**: create a database + user in hPanel, note the credentials.
3. **Domain root**: point the (sub)domain's document root at the app's
   `public/` directory (Websites → the domain → document root). Laravel's
   `.htaccess` in `public/` does the rest under LiteSpeed.
4. **SSH**: enable SSH access (Advanced → SSH) — everything below runs there.

## First deploy

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

# 6. Cache the boot work (rerun after every deploy)
php artisan config:cache && php artisan route:cache

# 7. Cron — ONE entry drives everything scheduled (sales sync every minute,
#    catalog every 30 min, push reminders hourly). hPanel → Advanced → Cron:
#    * * * * * /usr/bin/php /home/USER/app/artisan schedule:run >> /dev/null 2>&1

# 8. First data pull (the cron would do these too, this just avoids the wait)
php artisan twz:sync-sales && php artisan twz:sync-catalog
```

## Staging the frontend

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

## Every later deploy

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
