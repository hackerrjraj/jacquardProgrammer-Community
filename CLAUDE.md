# CLAUDE.md — Build rules for JPPHAF

Read this file and `docs/PROGRESS.md` at the start of every session.

## §1 Project

**JPPHAF — Jacquard Programmer Panel Humanitarian Assistance Fund.**

- Stack: **Laravel** (API backend) + **Next.js** (PWA frontend) + **MySQL**.
- Users: Computerized Flat Knitting / Jacquard programmers in Bangladesh.
- UI language: **Bengali first, English second.**

## §2 Fixed facts (never change without owner approval)

| Item | Value |
|---|---|
| Folders | `backend/`, `frontend/`, `database/`, `docs/` |
| Database | Portable MySQL 8.4 at `127.0.0.1:3307`, database `jpphaf`, data in `database/data` |
| API (Laravel) port | 8000 |
| Reverb WebSocket port | 8080 |
| Frontend (Next.js) port | 3000 |
| MySQL port | 3307 |
| Start / stop | `start-server.bat` / `stop-server.bat` |
| Site URL | http://localhost:3000 |

### Tool paths (not on PATH — always use full paths)

| Tool | Path |
|---|---|
| PHP 8.4 | `C:\laragon\bin\php\php-8.4.25-Win32-vs17-x64\php.exe` |
| Composer | `C:\laragon\bin\composer\composer.phar` — run as `php -d extension=zip C:\laragon\bin\composer\composer.phar ...` (zip is off in Laragon's php.ini) |
| Node 22 | `C:\laragon\bin\nodejs\node-v22\node.exe` |
| npm | `C:\laragon\bin\nodejs\node-v22\npm.cmd` (also `npx.cmd`) — first run `$env:Path="C:\laragon\bin\nodejs\node-v22;$env:Path"` so npm scripts can find node |

## §3 Workflow

1. One phase or sub-step per session.
2. Read `CLAUDE.md` and `docs/PROGRESS.md` first.
3. After each step: run checks, update `docs/PROGRESS.md` (status + Change log), then tell the owner to **/clear**.
4. **Git is used; commit after each step; never commit .env, database/data, backups or uploads.** The Change log in `docs/PROGRESS.md` is still kept. Remote: https://github.com/hackerrjraj/jacquardProgrammer-Community.git (branch `main`).

## §4 Rules

- Keep changes minimal and scoped to the current step.
- Do not touch unrelated files.
- Keep secrets only in `.env` files.
- For any UI work, open the matching screenshot in docs/design/ first and match it. (Pages without a screenshot, e.g. the dashboard, use the same visual style — MASTER_SPEC §8a.)
- The owner is not a developer — explain results simply.
- Backups: the owner takes backups only when he decides. Never remind him to back up unless he asks.
- Servers: The owner runs the site with start-server.bat in its own window. Never stop, kill or restart servers you did not start yourself. Before testing, check if ports 3000/8000/8080/3307 are already in use — if yes, use the running servers for testing and leave them running. If you start a server yourself for testing, stop only that process afterwards.

## §5 Phase 0 setup sub-steps

- **0a** — Project skeleton and docs
- **0b** — Portable MySQL
- **0c** — Laravel (backend)
- **0d** — Next.js (frontend)
- **0e** — start/stop scripts

## §6 Product requirements

See `docs/MASTER_SPEC.md`.

## §7 Build phases (13)

Same as `docs/MASTER_SPEC.md` §39 — keep both identical.

1. Design system (header/footer, colors, fonts, bn/en toggle)
2. Public pages: Home (hero background image slider), About, Contact
3. Registration/login + admin approval
4. 4a: Own profile edit (photo, experience, machines, portfolio) + new fields: employment status, designation, work history, portfolio category/title/description (§19); 4b: align design system with the screenshots (§8a tokens, header, footer) + Dashboard layout + left sidebar + profile pages, placeholders for data not built yet (§37a)
5. Programmers directory + filters + public profile — to match `programmers.png` / `programmer-profile.png` (§21–§22)
6. Gallery (`gallery.png`, categories Programs/Events/Training/Community/Workplace) + Notices + Home and About rebuilt to match `home.png` / `about.png` (§13–§14)
7. Fund contributions (payment submit, admin verify, ledger, dues) + dashboard summary numbers, Programmers List payment columns, payment history (§37a)
8. Assistance requests (apply, advisory voting, admin decision, payout)
9. Chat via Reverb (9a, 9b, ...)
10. Notifications + PWA push/install
11. Admin panel
12. Transparency report + security hardening
13. Final testing, backup, deployment prep
