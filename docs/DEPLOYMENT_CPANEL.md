# JPPHAF — Putting the site online on cPanel (simple steps)

Site: **https://sacomputer.com.bd** (what visitors see) · API: **https://api.sacomputer.com.bd** (the engine behind it).
Nothing in this guide touches your computer's local site. Do the steps in order and tick them off.

> Words you will see: **cPanel** = your hosting control panel. **Terminal** = a black command box inside cPanel
> (Advanced → Terminal). **File Manager** = cPanel's file browser. `~` means your home folder on the server.

---

## 0. What you need first

- [ ] Your cPanel login, with domain `sacomputer.com.bd` added.
- [ ] A free Pusher account (Step 9) — for live chat. (Skip it and chat still works, it just refreshes by itself every few seconds.)
- [ ] The two files made on your computer: double-click **`deploy-package.bat`** (in the project folder).
      First time only: copy `frontend\.env.production.example` to `frontend\.env.production` and fill in the Pusher key/cluster (or delete those two lines if you are not using Pusher).
      After a few minutes you get **`deploy\frontend.zip`** and **`deploy\backend.zip`**.
- [ ] A backup of your local data if you want to keep it (Step 11).

---

## 1. Choose PHP 8.4 (important)

cPanel → **MultiPHP Manager** → tick `sacomputer.com.bd` and `api.sacomputer.com.bd` → set **PHP 8.4 (ea-php84)** → Apply.
(Do not use alt-php84 for the API unless ea-php84 is missing; the cron and Terminal commands below use ea-php84.)
Then cPanel → **MultiPHP INI Editor / Select PHP Version → Extensions**.
In Select PHP Version → Extensions make sure these are ticked: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `ctype`, `tokenizer`, `xml`, `curl`, `bcmath`, `zip`.

## 2. Create the `api` sub-domain

1. cPanel → **Domains** (or "Subdomains") → **Create A New Domain**.
2. Domain: `api.sacomputer.com.bd`.
3. Untick "Share document root". **Document Root: `jpphaf/backend/public`** (this is outside `public_html`, which keeps your secret files private).
4. Create.

## 3. Create the database

1. cPanel → **MySQL® Databases**.
2. Create a database, e.g. `jpphaf` (cPanel adds your prefix, so it becomes something like `cpuser_jpphaf`). Write the full name down.
3. Create a user with a strong password. Write both down.
4. "Add User To Database" → choose that user and that database → **ALL PRIVILEGES** → Make Changes.

## 4. Upload the backend

1. File Manager → go to your home folder (not `public_html`).
2. Create a folder **`jpphaf`**, open it, create a folder **`backend`**, open it.
3. **Upload** `backend.zip` there, then right-click it → **Extract**. Delete the zip afterwards.
4. Check that `~/jpphaf/backend/public/index.php` exists.

## 5. Fill in the backend settings (`.env`)

1. In `~/jpphaf/backend` copy `.env.production.example` and name the copy **`.env`**. (If you do not see dot-files, in File Manager → Settings → tick "Show Hidden Files".)
2. Edit `.env` and replace every `<placeholder>`:
   - `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` → from Step 3.
   - `SUPER_ADMIN_*` → your admin phone, e-mail and a **strong** password (used once, to create the first admin).
   - `PUSHER_*` → from Step 9 (later; you can come back).
   - `VAPID_*` → made in Step 6.
3. The lines for the domains are already filled: `APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN=.sacomputer.com.bd`, `SANCTUM_STATEFUL_DOMAINS`. Leave them.
4. Keep `APP_ENV=production` and `APP_DEBUG=false`. Never share this file.

## 6. Run the setup commands (Terminal)

Open cPanel → **Terminal**. Use the PHP 8.4 program (it is called `php` below; if "php" is the wrong version use the full path `/opt/cpanel/ea-php84/root/usr/bin/php` in its place):

```
cd ~/jpphaf/backend
php -v
```
It must say 8.4.x. Then, one by one:

```
php artisan key:generate --force
php artisan webpush:vapid
```
`webpush:vapid` prints two keys — it also writes them into `.env`. (Never change them later: members would lose their notification subscriptions.)

```
php artisan package:discover
php artisan migrate --force
php artisan db:seed --class=SuperAdminSeeder --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

If a command says "Composer dependencies" or "vendor" is missing, the zip was not extracted fully — upload and extract again. (Composer is **not** needed: the zip already contains the `vendor` folder.)

Open https://api.sacomputer.com.bd/api/ping (or any API address) — you should see a short answer, not a long error page.

> After any later change to `.env`, run `php artisan config:clear` then `php artisan config:cache` again.

## 7. Upload the frontend (the website itself)

1. File Manager → home folder → in `jpphaf` create a folder **`frontend`**.
2. Upload `frontend.zip` there and **Extract**. Delete the zip. You should see `app.js`, `server.js`, `.next`, `public`, `node_modules`.
3. cPanel → **Setup Node.js App** / **Application Manager** (the name differs by host) → **Create Application**:
   - Node.js version: **22** (or 20)
   - Application mode: **Production**
   - Application root: `jpphaf/frontend`
   - Application URL: `sacomputer.com.bd`
   - Application startup file: **`app.js`**
4. Create / Start. Do **not** press "Run NPM Install" (everything needed is already inside the zip).
5. Open https://sacomputer.com.bd — the home page should appear.

> The site address of the API is baked into the frontend zip when `deploy-package.bat` runs (from `frontend\.env.production`). If you ever change it, make a new zip.

### Updating the live frontend (build on your computer → GitHub → server)

The server cannot run `next build` (thread limits), but it can run Node. So the build is made on your computer and published to a GitHub branch called **`deploy-frontend`** (it holds only the ready-to-run site: no source code, no `.env`, no secrets).

**On your computer**
1. Check `frontend\.env.production` (API address, and the Pusher key/cluster if you use Pusher).
2. Double-click **`deploy-frontend.bat`**. It builds in a separate folder (your local site is not touched), assembles the ready-to-run folder `deploy\frontend-branch`, and publishes it to the `deploy-frontend` branch. If a GitHub login window opens, sign in there.
   - Want to test first? Run `deploy-frontend.bat nopush`, then in `deploy\frontend-branch` run `node app.js` (set `PORT=3999` first) and open http://localhost:3999.

**On the server (cPanel → Terminal), first time**
```
cd ~/jpphaf
git clone --branch deploy-frontend --single-branch https://github.com/hackerrjraj/jacquardProgrammer-Community.git frontend
```
Then create the Node.js application (Step 7, point 3) with Application root `jpphaf/frontend`, startup file `app.js`.

**On the server, every update**
```
cd ~/jpphaf/frontend
git fetch origin deploy-frontend
git reset --hard origin/deploy-frontend
```
Then cPanel → Application Manager → **Restart**. (The branch is rewritten on every publish, so use `reset --hard`, not `pull`.)

## 8. Cron jobs (the "timer" that replaces background programs)

cPanel → **Cron Jobs** → add these two, each set to **Once Per Minute** (`* * * * *`):

```
* * * * * cd ~/jpphaf/backend && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/jpphaf/backend && /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

- The first runs the daily 2:00 PM payment reminder (and other timed jobs).
- The second sends push notifications that are waiting.
- If your host uses another PHP 8.4 path (e.g. alt-php84), replace `/opt/cpanel/ea-php84/root/usr/bin/php` (ask the host, or run `which php` after choosing 8.4).
- After uploading a new backend version, run once in Terminal: `php artisan queue:restart`.

## 9. Live chat with Pusher (free)

1. Go to https://pusher.com → sign up (free) → **Channels** → **Create app**.
2. Name: `jpphaf`. Cluster: nearest to you (e.g. **ap2 – Mumbai**). Front end: React, back end: Laravel. Create.
3. Open **App Keys**. You see `app_id`, `key`, `secret`, `cluster`.
4. In the **backend** `.env` (server) fill: `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`. Keep `BROADCAST_CONNECTION=pusher`. Then `php artisan config:clear && php artisan config:cache`.
5. In **`frontend\.env.production`** on your computer fill: `NEXT_PUBLIC_PUSHER_APP_KEY` (same key) and `NEXT_PUBLIC_PUSHER_APP_CLUSTER`. Run **`deploy-package.bat`** again and re-upload `frontend.zip` (Step 7) — these two values are baked in at build time.
6. The secret goes **only** into the server `.env`, never into the frontend.

No Pusher? Delete the two `NEXT_PUBLIC_PUSHER_*` lines and set `BROADCAST_CONNECTION=null` on the server. The chat then refreshes by itself: every 5 seconds inside an open chat, every 30 seconds for the unread badges.

## 10. HTTPS (SSL)

1. cPanel → **SSL/TLS Status**.
2. Tick `sacomputer.com.bd`, `www.sacomputer.com.bd` and `api.sacomputer.com.bd` → **Run AutoSSL**. Wait a few minutes until each has a green padlock.
3. Visit both addresses with `https://`. (Login only works over HTTPS because the cookies are secure.)

## 11. Move your local data online (only if you want it)

**Do not** do this if the online site should start empty — Step 6 already created empty tables and the first admin.

1. On your computer: double-click **`backup-now.bat`**. It makes `backups\jpphaf-….sql` and `backups\uploads-….zip`.
2. cPanel → **phpMyAdmin** → pick your database → **Import** → choose the `.sql` file → Go.
   (If the file is too big, compress it to `.zip`/`.gz` first, or ask the host to raise the limit.)
   This replaces the empty tables with your local ones, including accounts. Log in with the **local** admin password afterwards and change it.
3. Uploads (photos, payment proofs): upload `uploads-….zip` to `~/jpphaf/backend/storage/` and extract so that `storage/app/public` and `storage/app/private` get your files. Then `php artisan storage:link` again if the pictures do not show.
4. Run `php artisan migrate --force` once more (safe) and `php artisan config:clear`.
5. The database from the local PC may contain test accounts — check Dashboard → Members before announcing the site.

## 12. Final check

- [ ] https://sacomputer.com.bd opens, Bengali/English toggle works, pictures show.
- [ ] Log in as the admin → change the password right away (profile page).
- [ ] Register a test member, approve it, send a chat message (two browsers) — it appears live (Pusher) or within ~5 s (no Pusher).
- [ ] Both cron jobs listed in cPanel → Cron Jobs.
- [ ] `APP_DEBUG=false`; `.env` is **not** inside `public_html` or `backend/public`.
- [ ] Make a backup now and then (cPanel → Backup, and phpMyAdmin → Export).

## Updating the site later

1. On your computer: run `deploy-package.bat`.
2. Upload and extract the new zip over the old folder (the `.env` file and `storage` uploads are not in the zip, so they stay).
3. Backend: in Terminal run `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, `php artisan queue:restart`.
4. Frontend: Application Manager → **Restart**.

## If something goes wrong

| What you see | What to do |
|---|---|
| White page / "500" on the API | Look at `~/jpphaf/backend/storage/logs/` (newest file). Check `.env` has no `<placeholder>` left. Run `php artisan config:clear`. |
| "Your Composer dependencies require a PHP version…" | The API sub-domain is not on PHP 8.4 (Step 1) or Terminal `php` is another version — use the full path from Step 8. |
| Login works but logs out at once | `SESSION_DOMAIN=.sacomputer.com.bd`, `SESSION_SECURE_COOKIE=true`, both sites on https, then `php artisan config:cache`. |
| Pictures missing | `php artisan storage:link`; check `public/storage` exists inside `backend/public`. |
| Site shows old content after update | Application Manager → Restart; hard-refresh the browser (Ctrl+F5). |
| Chat not live | Check the Pusher keys on both sides (Step 9). The chat still updates by polling meanwhile. |
