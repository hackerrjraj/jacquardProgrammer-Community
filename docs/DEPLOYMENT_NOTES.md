# JPPHAF — Deployment notes (Phase 13)

Templates: `backend/.env.production.example`, `frontend/.env.production.example` (placeholders only).

> **cPanel shared hosting (Phase 14a): follow `docs/DEPLOYMENT_CPANEL.md`.** There no long-running process is allowed, so chat uses Pusher Channels (or polling), and the queue / scheduler run from two cPanel cron jobs. The checklist below is for a VPS / own server (Reverb, queue worker, Next.js behind a proxy).

## Content-Security-Policy — why `'unsafe-inline'` for scripts stays (Phase 13a review)

- Next.js puts small inline `<script>` tags in every page (hydration data, router bootstrap). Removing `'unsafe-inline'` needs either a **nonce** or **hashes**.
- A **nonce** must be new for every request, which forces every page to be rendered on demand. This site uses `cacheComponents` + partial prerendering (static shell + streamed content, `next.config.ts`), and a nonce would switch that off for all pages (slower pages, no cached shells).
- **Hashes** do not work: the inline script contents change on every request/build.
- So the policy stays: `script-src 'self' 'unsafe-inline'` (and `'unsafe-eval'` in development only). Everything else is strict: `default-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`, `connect-src`/`img-src` limited to the site, the API and Reverb, `form-action 'self'`, `base-uri 'self'`. The site has no user-written HTML (React escapes output), which limits the XSS risk the CSP is a backup for.
- Revisit later if needed: nonce via `src/proxy.ts` + dropping `cacheComponents` (a bigger change, owner decision).

## Error output in production

- Nothing in the code reads `APP_DEBUG`; only `config/app.php` does. With `APP_DEBUG=false` Laravel returns a generic JSON message ("Server Error") for `/api/*` — no stack trace, no file paths. Exception messages are only written to the log (`storage/logs`), never sent to the browser.
- Next.js production builds show a generic error page; details go to the server log only.
- `LOG_LEVEL=warning`, `LOG_STACK=daily` in the production template.
- Also set `expose_php = Off` in the server's php.ini (removes `X-Powered-By`; the app also strips it).

## Production checklist

**Server / HTTPS**
- [ ] Two sub-domains on one parent domain, e.g. `example.org` (site) and `api.example.org` (Laravel). Same parent domain is required for the login cookie (`SESSION_DOMAIN=.example.org`).
- [ ] HTTPS certificate on both (and on the WebSocket host). The app sends HSTS automatically over HTTPS.
- [ ] PHP 8.4 with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`; `expose_php = Off`; document root = `backend/public`.
- [ ] Node 22; run the frontend with `npm run build` then `npm start` (behind the reverse proxy), kept alive by a process manager.

**Environment**
- [ ] Copy `backend/.env.production.example` → `backend/.env`; `php artisan key:generate --force`; fill DB, domains, Super Admin, Reverb and VAPID values (`php artisan webpush:vapid`, `php artisan reverb:install`). `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_TIMEZONE=Asia/Dhaka`.
- [ ] Copy `frontend/.env.production.example` → `frontend/.env.production` **before** `npm run build` (values are baked in, and the CSP is built from them). `NEXT_PUBLIC_REVERB_APP_KEY` = backend `REVERB_APP_KEY`.
- [ ] `.env` files are never uploaded to a public place or shared.

**First deploy (database)** — see Phase 13b
- [ ] `php artisan migrate --force` on the new empty database (not on local data).
- [ ] Replace the demo Super Admin; do NOT copy demo programmers/test accounts (`jpphaf:remove-demo-programmers` only if demo data was copied).

**Storage**
- [ ] `php artisan storage:link` (public pictures). `storage/` and `bootstrap/cache/` writable by the web user. Payment screenshots/PDFs stay in the private folder (never linked).

**Background processes** (all must keep running; use a process manager such as Supervisor/systemd/NSSM)
- [ ] Queue worker: `php artisan queue:work --tries=1 --sleep=3` (web push, reminders). Restart it after every deploy: `php artisan queue:restart`.
- [ ] Scheduler (daily 2:00 PM payment reminder, Asia/Dhaka): cron `* * * * * cd /path/backend && php artisan schedule:run >> /dev/null 2>&1` (or keep `php artisan schedule:work` running).
- [ ] Reverb (chat): `php artisan reverb:start --host=127.0.0.1 --port=8080`, with the reverse proxy forwarding `wss://ws.example.org` → `127.0.0.1:8080`.
- [ ] Next.js: `npm start` on port 3000 behind the proxy.

**Cache commands (production only, after every deploy / `.env` change)**
```
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```
Locally they were tested (13a) and then cleared (`config:clear`, `route:clear`, `view:clear`) — never leave them cached on the dev PC, because `.env` changes would be ignored.

**Backups** (owner decides when/where)
- [ ] Database dump (`mysqldump`) on a schedule, stored off the server.
- [ ] Copy of `backend/storage/app` (uploaded pictures + private files) and of the production `.env` files, kept somewhere safe.
- [ ] Test restoring a backup once.

**After going live**
- [ ] Log in as Super Admin, change the password, enter real contact info in Site Settings and receiving numbers in Settings.
- [ ] Check: login, a payment submit, live chat (two browsers), a push notification, a private file link logged out (401), `/api/health`.
- [ ] `php artisan fund:check` (integrity) shows no problems.


## Server-side backups (VPS / cPanel)

On the live server the owner's PC scripts (`backup-now.bat`) do not apply. Use daily cron jobs instead.

**1. Create a backup folder** outside the web root (e.g. `/home/USER/backups`) and keep the DB password in `~/.my.cnf` (`chmod 600`) so it is not visible in the cron line:
```
[client]
user=DB_USER
password=DB_PASSWORD
```

**2. Daily database dump, keep 14 days** (cron, 02:30 every day):
```
30 2 * * * mysqldump --single-transaction --routines --triggers DB_NAME | gzip > /home/USER/backups/jpphaf-$(date +\%F).sql.gz && find /home/USER/backups -name 'jpphaf-*.sql.gz' -mtime +14 -delete
```
(In cron, `%` must be written as `\%`. On cPanel add it under *Cron Jobs*.)

**3. Weekly copy of uploads, keep 14 days** (Sunday 03:00):
```
0 3 * * 0 tar -czf /home/USER/backups/uploads-$(date +\%F).tar.gz -C /home/USER/path/to/backend/storage app && find /home/USER/backups -name 'uploads-*.tar.gz' -mtime +14 -delete
```

**4. Copy off the server.** A backup on the same server is lost if the server is lost. Pull the `backups` folder somewhere else regularly: `rsync -av USER@SERVER:/home/USER/backups/ /local/safe/folder/` (or download via cPanel File Manager / SFTP, or sync to cloud storage with rclone).

**5. Test a restore** on a spare database once: `gunzip < jpphaf-DATE.sql.gz | mysql DB_NAME_TEST`.

**Restore on the live server:** `php artisan down`, restore the dump, unpack uploads into `backend/storage/app`, then `php artisan up`.
