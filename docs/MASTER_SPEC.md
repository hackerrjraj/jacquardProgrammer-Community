# JPPHAF — Master Specification

**JPPHAF — Jacquard Programmer Panel Humanitarian Assistance Fund**

This is the product source of truth. Anything marked **TBD (owner decides)** must be confirmed by the owner before the phase that needs it is built. Items marked **Decided** were confirmed by the owner (see "Decided" below).

**Contents**

| Part | Sections |
|---|---|
| A. Overview (incl. §8a Design Reference) | §1–§8a |
| B. Roles & accounts | §9–§11 |
| C. Public pages | §12–§15 |
| D. Registration, profile & directory | §16–§22 |
| E. Gallery & notices | §23–§24 |
| F. Fund contributions | §25–§28 |
| G. Assistance requests | §29–§32 |
| H. Transparency & financial integrity | §33–§34 |
| I–K. Chat, notifications/PWA, admin panel, dashboard | §35–§37a |
| L. Security & non-functional | §38 |
| Build plan | §39 |

**Decided (owner, 2026-10-13 — before Phase 3a)**

| Topic | Decision | Section | Phase |
|---|---|---|---|
| Roles / statuses | Roles `member`, `committee`, `admin`, `super_admin`; statuses `pending`, `approved`, `rejected`, `suspended` | §9 | 3 |
| Member approval | Approve / reject / suspend / password reset by **admin** and **super_admin** only; role changes by **super_admin** only. The **committee** role only **votes on aid applications** (Phase 8) and has **no admin powers** (replaces the old rule "committee approves registrations"; owner, Phase 3b) | §9, §10, §18 | 3 |
| Login of non-approved users | Pending / rejected / suspended users **cannot log in**; the login error showing their status (and the rejection reason) is enough — no separate screen (owner, Phase 3b) | §11, §17 | 3 |
| Re-approval | Admin may **re-approve** rejected or suspended members (owner, Phase 3b) | §18 | 3 |
| Password reset | **By admin only for now.** Admin sets a temporary password → member must change it at next login. Email/SMS reset deferred to Phase 13 | §17 | 3 |
| Upload size limits | Images max **2 MB**, documents max **5 MB** | §38 | 4 |
| Dues start | ~~From the month of approval~~ → **replaced (owner, Phase 7d):** one fund **start month** for everybody (setting, default **October 2026**, Super Admin can change it in 7e). Every member owes every month from the start month to the current month, **whenever they joined**; months while **suspended are charged too**; re-approval changes nothing; nothing before the start month; no advance payments | §25, §28 | 7 |
| Fund receiving numbers | bKash / Nagad numbers stored in **settings** (editable) | §25 | 7 |
| Applying with unpaid dues | **Allowed** — dues / payment status does not matter (owner, Phase 8a) | §29 | 8 |
| Aid decisions (simple rules) | ~~Committee voting with quorum + majority, Super Admin breaks ties~~ → **replaced (owner, Phase 8a):** members, committee, admin (added Phase 8b) and Super Admin cast an **advisory Yes/No vote** (no minimum, no majority, no automatic decision); **admin / super_admin** make the final decision and record the payout | §29–§32 | 8 |
| Hosting / domain / SMS | Decided later, in Phase 13 | §38 | 13 |
| Home hero | Text left, image box right; the box rotates the admin-uploaded hero slides (owner, 2026-10-13) | §13 | 6 |
| Home stats | Calculated from real data only: members, companies, locations from profiles; money from the fund records (Phase 7). No typed numbers (owner, 2026-10-13) | §13 | 6, 7 |
| Expertise section | Admin-editable cards (title bn/en, text bn/en, image); defaults STOLL, SHIMA SEIKI, Sweater Programming (owner, 2026-10-13) | §13 | 6 |
| Directory filters | Experience 0–5 / 6–10 / 10+; district + machine under "More filters"; skill filter removed (owner, 2026-10-13) | §21 | 5 |
| Portfolio categories | jacquard, intarsia, programming, production, sampling, optimisation, training, other (bn/en labels) (owner, 2026-10-13) | §19 | 4 |
| Profile limits | Bio max 1000; designation max 100; work history max 15 entries (end ≥ start); portfolio max 12 items (title max 100, description max 300); documents max 20 (owner, Phase 4a-extra) | §19 | 4 |
| Document types | `nid`, `birth_certificate`, `certificate`, `other` — required on upload (owner, Phase 4a-extra) | §19 | 4 |
| Name / email | Member cannot change own name, can change own email; admin / Super Admin can change name and email (owner, Phase 4a-extra) | §19 | 4 |
| Chat block + report | **Option A** (owner, Phase 9d): members can **block** each other (no messages either way, old messages stay) and **report one received message** to admins (admins see only a text copy of that message — never the chat). Unavailable partners (suspended / pending / removed / demo) can't receive messages; history stays readable | §35 | 9 |
| Committee and private documents | Committee sees members' document list (type + date only), **cannot open/download** (API 403). ~~Only during review of a member's aid application may the committee view that member's documents~~ — **dropped (owner, Phase 8a):** the committee gets no access to private profile documents, also not during aid review | §10, §30 | 4, 8 |

---

## A. Overview

### §1 Purpose

JPPHAF is a community + welfare-fund Progressive Web App (PWA) for **Computerized Flat Knitting / Jacquard programmers in Bangladesh**. It lets programmers:

- present themselves professionally (profile, skills, machines, portfolio) and be found by others;
- pay a monthly contribution into a shared humanitarian fund;
- apply for financial help in hard times (medical, accident, death, job loss, other), decided by admin / Super Admin after an advisory Yes/No vote of the members;
- see transparently how the fund's money is collected and spent;
- chat 1-to-1 with other approved members.

### §2 Users and context

- Members are working programmers, mostly using **mobile phones**, often on slow connections.
- Many are more comfortable in **Bengali** than English.
- Payments happen through Bangladeshi mobile financial services (bKash, Nagad, Rocket), bank, or cash — the site **records and verifies** payments; it does not process money itself.

### §3 Technology stack

| Layer | Technology |
|---|---|
| Backend (API) | Laravel, in `backend/` |
| Auth | Laravel Sanctum |
| Realtime | Laravel Reverb (WebSocket) |
| Frontend | Next.js (App Router, TypeScript, Tailwind CSS), PWA, in `frontend/` |
| Database | MySQL 8.4 (portable, inside the project) |

### §4 Fixed facts

Copied from `CLAUDE.md` §2 — change only with owner approval.

| Item | Value |
|---|---|
| Folders | `backend/`, `frontend/`, `database/`, `docs/` |
| Database | Portable MySQL 8.4 at `127.0.0.1:3307`, database `jpphaf`, data in `database/data` |
| API (Laravel) port | 8000 |
| Reverb WebSocket port | 8080 |
| Frontend (Next.js) port | 3000 |
| Start / stop | `start-server.bat` / `stop-server.bat` |
| Site URL (local) | http://localhost:3000 |

### §5 Development workflow

- One phase (or sub-step) per session; read `CLAUDE.md` and `docs/PROGRESS.md` first.
- **No Git.** History is the Change log in `docs/PROGRESS.md`.
- After every step the owner **zip-backs up the project folder**, then types `/clear`.

### §6 Language

- **Bengali is the default** language for every page.
- A **bn ⇄ en toggle** in the header switches the **whole site** (all menus, labels, buttons, messages, emails/notifications, admin panel).
- The choice is remembered (cookie / user setting) and applies to the next visit.
- All UI text lives in translation files (no hard-coded strings in components).
- User-written content (bios, notices, descriptions) is shown as written. Whether admin content (notices, About, slides) needs separate bn and en fields: **TBD (owner decides)** — default proposal: optional English field, falls back to Bengali.
- Numbers and dates are shown with Bengali digits in Bengali mode, Latin digits in English mode.

### §7 Locale and device

| Item | Value |
|---|---|
| Currency | BDT (৳), whole taka, stored as integers |
| Timezone | Asia/Dhaka for all dates, months and reports |
| Layout | Mobile-first; must work well at 360 px width, scales up to desktop |

### §8 Visual design and layout

- Layout follows the demo: https://pixel-perfect-capture-1963.lovable.app/
  - **Home**, **Programmers** (list with filters), **Profile**, **Gallery** (category tabs), **About**, **Contact**.
- Site name is **JPPHAF** everywhere (never "JPA"). The full name appears in the footer, About page and page metadata.
- Colours, fonts, radius and shadows: the **design system in §8a** (taken from the official screenshots; replaces the Phase 1 values). Logo: round teal badge with the text "JPPHAF" (§8a); a final graphic logo can still replace it later.
- **The official design screenshots (§8a) now take priority over the demo link above.**

### §8a Design Reference

Adopted by the owner on 2026-10-13. The official design is a set of screenshots in `docs/design/` (saved by the owner as JPEG files with WhatsApp names):

| File in `docs/design/` | Page | Size |
|---|---|---|
| `WhatsApp Image 2026-10-13 at 11.01.40 AM.jpeg` | Home (§13) | 517×1600 (low resolution — use for layout/order only) |
| `WhatsApp Image 2026-10-13 at 1.jpeg` | About (§14) | 1008×1600 |
| `125.jpeg` | Programmers directory (§21) | 1230×1600 |
| `WhatsApp Image 2026-10-13 at 11.01.3.jpeg` | Public programmer profile (§22) | 1186×1600 |
| `WhatsApp Image 2026-10-13 at 11.01.39 AM.jpeg` | Gallery (§23) | 1101×1600 |

(Earlier text in this spec calls them `home.png`, `about.png`, `programmers.png`, `programmer-profile.png`, `gallery.png` — same screenshots. No `lovable-code/` folder was provided.)

**Rules**

- All public pages and the profile pages must **match these screenshots**: layout, spacing, colours, typography, card style, the **dark navy hero with a grid pattern**, **teal buttons**, and the footer.
- For any UI work, open the matching screenshot first and match it (also in `CLAUDE.md` §4).
- The **member dashboard** (§37a) has no screenshot: it uses the **same visual style** (colours, fonts, cards, buttons).
- **Branding:** wherever the design shows "Jacquard Programmer Association Bangladesh" or "JPA", use the official name **"JPPHAF — Jacquard Programmer Panel Humanitarian Assistance Fund"**. The logo badge text is **"JPPHAF"**.
- People, factory and event photos in the screenshots are **placeholders**. Real images are uploaded by admins (slides, gallery) or members (profile photo, portfolio).

**Design tokens: current vs. screenshots**

Extracted on 2026-10-13. Colours were **measured from the screenshot pixels** (JPEG, so ±2–3 per channel); sizes are **estimated** — the screenshots are scaled down (about 0.64× of a ~1920 px wide desktop), so px values below are converted back to real size and rounded to the nearest Tailwind step. "≠" marks a difference from the current design system.

| Token | Current design system (Phase 1) | Screenshots | |
|---|---|---|---|
| Primary (buttons, logo badge, links, icons) | teal `#0f766e`, hover `#115e59`, soft `#ccfbf1` | bright teal **gradient** left→right `#00aebb` → `#14b8a6` (Join Community, Contact Programmer, Send Message, logo badge). Solid fallback `#0fb3b0`; text/icon teal `#06a3b5`; soft tint `#e0f7f7` | ≠ much brighter, gradient |
| Secondary blue (active filter / tab chip, icon boxes) | — | active chip `#0560a5`; icon boxes gradient `#0175b0` → `#21a5c1` | ≠ new token |
| Dark navy (hero, dark cards, CTA band) | navy `#1e3a5f` | hero gradient `#021d30` (left) → `#093c57` (right), faint grid lines + soft teal glow on the right; Vision card / CTA band `#06243a` → `#0a3653` | ≠ much darker |
| Footer | navy | `#021728`, divider line `#0b2236`, copyright bar same colour | ≠ |
| Accent | amber `#f59e0b` | **not used** in the screenshots | ≠ keep only for warnings (e.g. pending status), not for branding |
| Page background (alternate sections) / surface | `#f8fafc` / `#ffffff` | `#f4f8fb` / `#ffffff` — sections alternate white and `#f4f8fb` | ≈ |
| Input / search background | white | `#f4f8fb`, border `#e6ebf0` | ≠ |
| Heading text / body / muted | `#0f172a` / – / `#64748b` | headings dark navy `#0b1f33`; body `#4b5868`; muted `#6e7a88` | ≈ (navy tint) |
| Border | `#e2e8f0` | `#e8ecf1` (cards, chips, outline buttons) | ≈ |
| Status badge "Running job" | success `#15803d` | bg `#e0f6ea`, text/dot `#2f9e6b` | ≠ softer |
| Status badge "Jobless" | danger `#dc2626` | bg `#ffe8e6`, text/dot `#e0475b` | ≠ softer |
| Radius | card 14 px, control 8 px | cards **16 px**; image tiles 12–16 px; buttons, filter chips, badges **pill (full)**; inputs 10 px; Send Message button 10 px | ≠ |
| Shadows | two card shadows | cards: very soft `0 4px 20px rgba(2,23,40,0.06)` + 1 px border; overlapping panels (search panel, profile header card): stronger `0 20px 40px rgba(2,23,40,0.12)`; images: `0 20px 40px rgba(2,23,40,0.18)` | ≠ |
| Fonts | Hind Siliguri (bn) + Inter (en) | **headings: Poppins** (bold geometric), **body/UI: Inter**. Bengali stays **Hind Siliguri** | ≠ add Poppins for English headings |
| Hero title | default | ≈ 48 px (text-5xl), Poppins bold, white, line-height ~1.1; Home hero ≈ 56 px with the 2nd line in teal | ≠ |
| Section heading | default | ≈ 30–36 px (text-3xl/4xl), Poppins bold, navy, centred or left | ≠ |
| Eyebrow label (above headings) | — | ≈ 11–12 px, uppercase, letter-spacing ~0.2em, semibold, teal | ≠ new |
| Card title / body / meta | default | name ≈ 18 px Poppins semibold; body ≈ 14 px; meta lines ≈ 13–14 px with 16 px teal/blue line icons | ≠ |
| Nav links | default | ≈ 12–13 px **uppercase**, semibold, letter-spaced, white; active = teal | ≠ |
| Spacing | default | container max ≈ 1280 px with side padding; section padding ≈ 80–96 px top/bottom; card padding ≈ 24 px; grid gap ≈ 24 px | ≈ |
| Hero | plain teal / image slider | dark navy gradient + grid pattern; text left. Inner pages: eyebrow + title + subtitle. Home: text left + image box right + stats cards below (§13) | ≠ |

**Design system (target — replaces the Phase 1 values; apply in `frontend/src/app/globals.css` in step 4b)**

- **Colours:** `primary` `#0fb3b0` with gradient `#00aebb → #14b8a6` for primary buttons and the logo badge; `primary-text` `#06a3b5`; `primary-soft` `#e0f7f7`; `blue` `#0560a5` (active chip/tab) + icon-box gradient `#0175b0 → #21a5c1`; `navy` `#021d30` → `#093c57` (hero gradient), `navy-card` `#06243a` → `#0a3653`, `footer` `#021728`; `background` `#f4f8fb`; `surface` `#ffffff`; `heading` `#0b1f33`; `text` `#4b5868`; `muted` `#6e7a88`; `border` `#e8ecf1`; `success` text `#2f9e6b` / bg `#e0f6ea`; `danger` text `#e0475b` / bg `#ffe8e6`; `warning` amber `#f59e0b` (warnings only).
- **Typography:** Poppins (headings, English) + Inter (body, English) + Hind Siliguri (all Bengali text). Eyebrow label style as above. Nav uppercase.
- **Shape:** cards 16 px radius + 1 px border + very soft shadow; buttons, chips and badges pill-shaped; inputs 10 px radius on `#f4f8fb`.
- **Buttons:** primary = teal gradient, white semibold text, pill (Join Community, Contact Programmer, Explore Programmers); outline = white with 1 px border, blue/navy text (View Profile, Back to Programmers, View Full Gallery); dark-hero secondary = transparent with white text/border (Join Our Community).
- **Components seen in the screenshots:**
  - **Header:** transparent over the navy hero; logo badge + 2-line name on the left, uppercase nav in the centre, teal pill button on the right.
  - **Footer:** 4 columns (brand + short text, Quick Links, Community, Contact with icons), then a thin divider and a centred © line.
  - **Filter / category chips:** active = solid blue pill; inactive = light grey pill.
  - **Status badge:** small pill with a coloured dot.
  - **Overlapping panel:** the search/filter panel and the profile header card overlap the bottom of the hero.
  - **Programmer card:** square-ish photo with rounded corners, badge, name, designation, bio, 3 icon lines, full-width outline "View Profile" button.
  - **Machine chip card:** small white card, teal icon, uppercase label.
  - **Portfolio card:** image on top, teal uppercase category, title, description.
  - **Experience timeline:** teal dots on a thin line.
  - **Icon box:** blue gradient rounded square with a white icon.
- The Phase 1 values (teal `#0f766e`, navy `#1e3a5f`, 14 px cards) are replaced. The `frontend/` code is not changed in this docs step.

---

## B. Roles & accounts

### §9 Roles

| Role | Who |
|---|---|
| **Guest** | Not logged in. |
| **Pending member** | Registered, waiting for admin approval. |
| **Approved member** | Registration approved; full member. |
| **Committee member** | An approved member chosen by the Super Admin to **vote on aid applications** (Phase 8). **No admin powers** — Decided (Phase 3b). |
| **Super Admin** | Final control: settings, roles, everything a committee member can do. |

Technically a user has a **role** (`member`, `committee`, `admin`, `super_admin`) and a **status** (`pending`, `approved`, `rejected`, `suspended`) — **Decided.** `admin` = manages members (approve / reject / suspend / reset password); `suspended` = an approved member who is blocked from logging in until approved again.

### §10 Permission matrix

| Action | Guest | Pending | Approved | Committee | Super Admin |
|---|:-:|:-:|:-:|:-:|:-:|
| View public pages, directory, public profiles, gallery, notices | ✔ | ✔ | ✔ | ✔ | ✔ |
| View the Fund Report (**members-only**, §33) | ✖ | ✖ (pending / suspended / rejected) | ✔ | ✔ | ✔ |
| Send contact form | ✔ | ✔ | ✔ | ✔ | ✔ |
| Register | ✔ | – | – | – | – |
| Edit own profile | – | ✔ (not listed publicly) | ✔ | ✔ | ✔ |
| Appear in directory | – | – | ✔ | ✔ | ✔ |
| Submit contribution payments, see own dues | – | – | ✔ | ✔ | ✔ |
| Apply for assistance (not demo accounts) | – | – | ✔ | ✔ | ✔ |
| Advisory Yes/No vote on others' open aid requests (role `admin` votes too — owner, Phase 8b; not demo accounts) | – | – | ✔ | ✔ | ✔ |
| 1-to-1 chat | – | – | ✔ | ✔ | ✔ |
| Approve / reject / suspend / re-approve members, reset passwords | – | – | – | – (only role `admin`) | ✔ |
| Verify / reject payments, view all dues | – | – | – | ✔ | ✔ |
| Decide aid requests (approve with amount / reject with reason) — role `admin` + Super Admin (Phase 8a) | – | – | – | – | ✔ |
| Record aid payout — role `admin` + Super Admin (Phase 8a) | – | – | – | – | ✔ |
| View assistance supporting documents — applicant, role `admin` + Super Admin (Phase 8a) | – | – | own only | – | ✔ |
| Manage content (slides, gallery, notices, About) | – | – | – | ✔ | ✔ |
| Read contact messages | – | – | – | ✔ | ✔ |
| Change settings (monthly amount, quorum, limits) | – | – | – | – | ✔ |
| Assign / remove committee members | – | – | – | – | ✔ |
| View audit log (admin / super_admin only — Decided 4b-2) | – | – | – | – | ✔ |
| Dashboard: own summary + own payment history (§37a) | – | – | ✔ | ✔ | ✔ |
| Dashboard: summary cards, Programmers List, payment history of **all** members (§37a) | – | – | – | ✔ read-only | ✔ |
| Dashboard: Programmers List + member profiles, read-only (§37a) | – | – | – | ✔ read-only | ✔ |
| Member private documents (profile, §19): see the list (type + upload date) | – | – | own | ✔ list only | ✔ |
| Member private documents: open / download the file | – | – | own | – (aid review only, §30) | ✔ |
| Dashboard: edit / act on members' data (§37a) | – | – | – | – | ✔ |

- Dashboard (§37a, owner 2026-10-13): role `admin` has the same dashboard access as Super Admin (full data, all members). Committee sees all members' dashboard data **read-only**. A member sees only their own data. Sidebar items are shown per these permissions.
- **Decided (Phase 4b-2, owner):** committee can view the **Programmers List and member profiles read-only** (`GET /api/admin/members`, `GET /api/admin/members/{id}/profile`); all changes (approve, reject, suspend, reset password, role, name/email) stay `admin` / `super_admin`. The **Audit Log is admin / super_admin only** (not committee).
- **Decided (Phase 4 follow-up, owner):** committee must **not open or download members' private profile documents** (NID etc.). On a member's profile the committee sees only the document list — **type and upload date** (no file name, no size, no download button). Download (`GET /api/profile/documents/{id}/download`) is allowed only for the **owner, admin and super_admin**; the API refuses committee with **403** (not just a hidden button). ~~Exception, built in Phase 8: committee will be able to view the documents of members who submitted an aid application~~ — **dropped (owner, Phase 8a):** no exception; the committee never opens private profile documents.

- **Decided (Phase 8a, owner — simple aid rules, §29–§32):** members, committee and Super Admin may cast one **advisory** Yes/No vote on other members' open aid requests; ~~role `admin` does not vote~~ → **role `admin` votes too** (owner, Phase 8b) — every approved non-demo account votes, never on their own request. **admin / super_admin** decide (approve with amount / reject with reason) and record the payout; nobody acts on their own request.
- Member management — **Decided (Phase 3a):** only role `admin` or `super_admin` can approve / reject / suspend members and reset passwords; only `super_admin` can change roles. Nobody can act on their own account; only `super_admin` can act on admin / super_admin accounts.
- Committee — **Decided (Phase 3b):** the committee's role is voting on aid applications (Phase 8); it has no admin powers. ⚠ The other "Committee ✔" rows above (verify payments, content, contact messages, audit log) were written before this decision — confirm each with the owner in the phase that builds it (likely `admin` instead).
- Whether content management should be Super-Admin-only: **TBD (owner decides)** — default as above.

### §11 Account rules

- Every API route checks role **and** status on the server; the frontend only hides buttons.
- Pending, rejected and suspended users **cannot log in** (Phase 3a) — **Decided (Phase 3b)**. The login error tells them their status in bn/en; rejected users see the reason there. That message is enough — no separate status screen. A logged-in member who gets suspended is logged out at the next request. (Letting pending users edit their profile before approval: revisit in Phase 4.)
- Changes of role or status are written to the audit log (§34).

---

## C. Public pages

### §12 Global layout

- **Header:** logo/JPPHAF, menu (Home, Programmers, Gallery, Notices, About, Contact — no Fund Report: it is members-only), bn/en toggle, Login/Register or user menu. Mobile: hamburger / bottom-friendly menu.
- **Footer:** full name, short description, quick links, contact info.

### §13 Home

- **Hero:** admin-managed slides (image, title, subtitle, optional button text + link, order, active on/off) — shown inside the image box of the new hero layout (see Design update below).
- **Stats (4 cards, one row):** programmers, companies, years of experience, locations — always calculated, never typed in (see below). **No fund money on the public site** (Fund Report is members-only, §33).
- **Our Programmers (owner, 2026-10-13 — replaces "featured programmers"):** up to 45 approved, publicly listed programmers (never demo) in random order, shown in up to 3 endless auto-scrolling rows. **Featured programmers (owner, 2026-10-16, Phase 11b-2, Option A):** admin / super_admin pick programmers and their order (Dashboard → Featured Programmers); they come first in the rows, in that order, and the rest follow in random order. No badge or visual difference on the card. Someone featured who is suspended / no longer listed is left out (the pick is kept).
- **Latest notices:** newest published notices with "see all" link.

**Design update (owner, 2026-10-13 — `home.png`, §8a)** — Home is rebuilt to match the screenshot, in this order:

1. **Hero** with image + stats (dark navy, grid pattern).
2. **About** block.
3. **Our Programmers** (auto-scrolling rows of directory cards §21 + "See all programmers"; replaces the screenshot's Featured Programmers grid).
4. **Expertise** section.
5. **Community Gallery** preview (from §23).
6. **Latest Notices** (§24).
7. **Call-to-action** band.

**Decided (owner, 2026-10-13):**

- **Hero layout:** as in the Home screenshot — **text on the left, image box on the right**. The image box **rotates the admin-uploaded hero slides** (the slides built in Phase 2; same rotation rules: autoplay, pause on hover, no motion with reduced-motion).
- **Stats are calculated automatically from real data — no manually typed numbers:**
  - **members** = approved members;
  - **companies** = distinct workplaces in approved members' profiles;
  - **locations** = distinct districts in approved members' profiles;
  - **money figures** (collected, disbursed, balance) = from the fund records (ledger, §34) once Phase 7 exists; until then they are not shown (or 0).
  - ⚠ The screenshot's 4th card "20+ Years Experience" is not covered by this decision — confirm what it should count (e.g. highest experience among members) or replace it with a fund figure.
- **Expertise section:** **admin-editable cards** — title (bn/en), short text (bn/en), image, order, active on/off. **Default 3 cards** (seeded): **STOLL** ("Computerized Flat Knitting"), **SHIMA SEIKI** ("Advanced Knitting Technology"), **Sweater Programming** ("Professional Programming & Production") — short texts as in the screenshot, admin can change them. Admin screen: minimal in Phase 6, full in the admin panel (§37, Content).

### §14 About

- Admin-editable content: mission, how the fund works, committee list. Full name of JPPHAF shown.
- **Built (Phase 11c, 2026-10-16):** the "Who we are" text is edited in Dashboard → Site Settings (bn + en; the built-in text is shown when both are empty). The **committee list** is automatic: approved members with the `committee` role, cards with photo, name and an optional position, in an admin-set order, directly below the About text — hidden when there are none. Never mobile numbers or emails.
- **Design update (owner, 2026-10-13 — `about.png`, §8a):** sections **Who we are**, **Mission** (list), **Vision** (card), **How we support our members** (cards), **contact block**.

### §15 Contact

- Contact info (phone, email, address — values **TBD (owner decides)**). **Built (Phase 11c):** admin / super_admin enter 2 phones, WhatsApp, email, Facebook link (https), address and office hours (bn + en) in Dashboard → Site Settings; footer, Contact and About show only the filled-in lines.
- Contact form: name, mobile, optional email, subject, message. Saved to the database; listed in the admin panel (§37) with read/unread status.
- Rate-limited and spam-protected (§38).

---

## D. Registration, profile & directory

### §16 Registration

| Field | Rules |
|---|---|
| Full name | required |
| Mobile | required, Bangladesh format `01[3-9]XXXXXXXX` (11 digits; `+880` accepted and normalised), **unique**, used for login |
| Password | required, min 8 characters, confirmed |
| Email | optional |
| District | required, from the list of 64 districts |
| Workplace | required (factory/company name) |
| Years of experience | required, number |
| Machine brands / types | required, multi-select (§19) |
| Skills | required, multi-select (§19) |

After registering, status = **pending**. The user sees "waiting for approval".

### §17 Login and password

- Login with **mobile + password** (Sanctum).
- Login and register are rate-limited (§38).
- Password reset — **Decided:** by admin only for now. Admin creates a temporary password (shown once) and tells the member; the member must set a new password at the next login (`must_change_password`). Email/SMS reset is deferred to Phase 13.
- Members can change their own password (current + new password).

### §18 Admin approval

- Admin / Super Admin sees the pending list, opens a profile and **approves** or **rejects with a reason** (reason required). An approved member can be **suspended**. Admin may **re-approve** a rejected or suspended member — **Decided (Phase 3b)**. Only `admin` / `super_admin` do this; the committee does not (Phase 3b).
- Member is notified (§36). Rejected members see the reason in the login error. Since rejected users cannot log in, they cannot edit and re-apply themselves; an admin re-approves them instead (Phase 3b).

### §19 Profile edit

Members can edit:

- profile photo;
- bio;
- years of experience, workplace, district;
- machines (brand + type, e.g. Stoll, Shima Seiki, Cixing, Steiger … — exact list admin-managed, with "Other");
- software skills (e.g. Stoll M1plus, Shima SDS-ONE APEX … — admin-managed list, with "Other");
- portfolio images (with optional caption);
- contact visibility settings (§20).

**New profile fields (owner, 2026-10-13 — from the design, §8a):**

| Field | Rules |
|---|---|
| Employment status | `running_job` / `jobless` (shown as a badge; used by the directory filter) |
| Designation | text, e.g. "Senior Jacquard Programmer", **max 100** characters |
| Work history | list of entries: company (max 150), position (max 100), start year, end year **or "present"** (shown as the Experience timeline, §22). **Max 15 entries**; end year ≥ start year; years from 1950 to the current year — Decided (owner, Phase 4a-extra) |
| Portfolio item | image + **category**, **title**, **description** (replaces "optional caption") |

**Limits and rules — Decided (owner, Phase 4a-extra, 2026-10-13):**

| Item | Rule |
|---|---|
| Bio | max **1000** characters |
| Portfolio | max **12 items** per member. Image JPG/PNG/WebP max 2 MB (public); category from the list below; title **required**, max 100; description optional, max 300. Members can add, edit, delete and reorder; removing an item or replacing its image deletes the old file. |
| Documents | private files (§38) with a **required type**: `nid` (National ID / জাতীয় পরিচয়পত্র), `birth_certificate` (Birth certificate / জন্ম নিবন্ধন সনদ), `certificate` (Certificate / সনদপত্র), `other` (Other / অন্যান্য). PDF/JPG/PNG max 5 MB; max **20** per member. Opened only by the member, admin and Super Admin; committee sees the list (type + date) only — §10, §30. |
| Name | members **cannot** change their own name; **admin / Super Admin** can (same rules as other admin actions: not on yourself, admin accounts only by Super Admin). |
| Email | members **can** change their own email (valid, unique; may be left empty); admin / Super Admin can change it too. |
| Other profile fields | only the member edits them; admins can view but not edit. |
| Audit log | every change is logged with old and new values. |

**Portfolio categories — Decided (owner, 2026-10-13):** a fixed list with bn/en labels (stored as the key):

| Key | English | Bengali |
|---|---|---|
| `jacquard` | Jacquard | জ্যাকার্ড |
| `intarsia` | Intarsia | ইন্টারশিয়া |
| `programming` | Programming | প্রোগ্রামিং |
| `production` | Production | প্রোডাকশন |
| `sampling` | Sampling | স্যাম্পলিং |
| `optimisation` | Optimisation | অপটিমাইজেশন |
| `training` | Training | প্রশিক্ষণ |
| `other` | Other | অন্যান্য |

(The screenshot's "Mentoring" example belongs under `training`.)

Mobile change needs admin action (it is the login) — no screen for it yet. Name change: by admin / Super Admin only — **Decided** (see table above).

### §20 Contact visibility

Per member, each of these can be set to **Public / Members only / Hidden**:

| Item | Default |
|---|---|
| Mobile number | Members only |
| Email | Hidden |
| Workplace | Public |

Pending/rejected users' contact data is never shown to anyone except committee.

### §21 Programmers directory

- Lists **approved** members only.
- Must match `programmers.png` (§8a). Updated by the owner on 2026-10-13:
- **Search** by name / company (workplace) / location (district).
- **Filters (chips):** All / Running Job / Jobless / **0–5 years / 6–10 years / 10+ years** — Decided (owner, 2026-10-13). "10+" = more than 10 years (11 and up), so every member falls in exactly one range.
- **"More filters" toggle** (closed by default) with **district** and **machine** filters — Decided (owner, 2026-10-13). The **skill filter is removed**.
- **Card:** photo, employment status badge, name, designation, short bio, company, experience, location, **"View Profile"** button.
- Mobile / email shown on cards only as the member's visibility settings allow (§20).
- **No payment data (dues, paid amounts) is ever shown publicly.**
- Paginated.

### §22 Public profile page

- Must match `programmer-profile.png` (§8a). Updated by the owner on 2026-10-13:
  - **Header card:** photo, name, designation, employment status badge, company, location, experience, "Contact Programmer" button.
  - **Professional Profile:** bio.
  - **Experience timeline:** work history entries (§19).
  - **Machine Experience:** machines as chips (software skills shown too).
  - **Work Portfolio:** grid of portfolio items (image, category, title, description).
- **"Contact Programmer"** shows mobile / email **only if the member allows it** (§20). Direct messaging (the "Message" button, §35) comes later in the chat phase (Phase 9).
- No payment data is ever shown publicly.

---

## E. Gallery & notices

### §23 Gallery

- Admin-managed images with title, category, order, published on/off.
- Public page with **category tabs** ("All" + each category). Categories are admin-managed. Initial categories — **Decided (owner, 2026-10-13):** **Programs, Events, Training, Community, Workplace**.
- Must match `gallery.png` (§8a).

### §24 Notices

- Admin-managed: title, body, optional image/attachment, publish date, pinned on/off, published on/off.
- Public list (newest first, pinned on top) and detail page. Latest notices appear on Home.
- Publishing a notice triggers a notification (§36).

---

## F. Fund contributions (Phase 7)

### §25 Monthly contribution amount

- Default: **200 BDT per approved member per month**.
- Super Admin can change the amount **for future months only**. Stored as a setting history: `amount`, `effective_from` (month). A month's due amount = the amount effective for that month. Past months keep their old amount.
- Month from which a member owes contributions: ~~the month of approval~~ — **changed (owner, Phase 7d):** the fund's **start month** (setting `fund_start_month`, default **2026-10**, editable by the Super Admin in 7e). Every member's dues run from the start month to the current month, no matter when they joined (approved March 2027 → owes Oct 2026 – Mar 2027). Months while a member is **suspended are charged too** (due when they come back). Re-approval changes nothing. No dues before the start month; no advance payments (only start month → current month can be paid).
- Who owes dues: **approved and suspended** accounts (pending / rejected registrations owe nothing). Fund-wide figures leave out the local demo programmers.
- Fund's receiving numbers (bKash / Nagad) shown to members: stored in **settings**, editable — **Decided.**

### §26 Payment submission

Member submits:

| Field | Rules |
|---|---|
| Method | bKash / Nagad / Rocket / Bank / Cash |
| Sender number | required for bKash/Nagad/Rocket |
| Transaction ID (TxID) | required except for cash; must be unique per method |
| Amount | required, BDT |
| Month(s) covered | one or more unpaid months |
| Screenshot | optional image |

Status on submit: **pending verification**. Amount should equal the sum of dues for the chosen months; partial / over-payment handling: **TBD (owner decides)** — default: must match exactly.

### §27 Verification and ledger

- Committee / Super Admin **verifies** or **rejects with a reason**. — **Decided (Phase 7a, owner):** verify / reject is done by role **admin / super_admin** (not committee); nobody verifies their own payment.
- **Only verified payments** create a ledger entry (type `contribution`) and mark the months as paid.
- Rejected payments stay in history with reason; member is notified (§36).
- The ledger is append-only (§34).

### §28 Dues

- **Member** sees each month since the fund start month: paid / pending / due, with amounts, and total due.
- **Admin** sees dues for every member (who owes how many months / how much), with filter and search.

---

## G. Assistance requests (Phase 8)

### §29 Applying

**Simple rules — Decided (owner, Phase 8a; replaces the earlier committee-voting / quorum design):**

- Any **approved, non-demo** account can apply (member, committee, admin, super_admin). **Dues / payment status does not matter.**
- **One open request per member at a time** (open = submitted, or approved but not yet paid).
- A member **may apply on behalf of a family member** (e.g. a death case): optional "applying for" name + relation.

| Field | Rules |
|---|---|
| Reason | Medical / Accident / Death / Job loss / Other |
| Custom reason text | **required when reason = Other** |
| Requested amount | required, whole taka, ≥ 1. **No maximum limits** (§31) |
| Description | required |
| Applying for (name + relation) | optional; relation required when a name is given |
| Supporting documents | **optional, 0–5 files**, JPG / PNG / WebP / PDF, max **5 MB** each, private (§38) — opened only by the applicant, admin and Super Admin |
| Consent to show name publicly | checkbox, default **unchecked** (§33) |

Each request gets a **Case ID** `JPPHAF-AR-YYYY-NNNN` (year of submission + running number).

### §30 Advisory voting and decision

Status flow:

```
submitted → approved → paid
          ↘ rejected
          ↘ cancelled
```

| Step | Who | What |
|---|---|---|
| submitted | every logged-in approved account — member, committee, admin, Super Admin (not the applicant, not demo accounts; admin added by the owner in Phase 8b) | **advisory Yes / No vote**, one per person, can be changed until the decision |
| submitted → cancelled | the applicant | cancels their own request (only while no decision has been made) |
| submitted → approved | admin / super_admin (never the applicant) | sets the **approved amount**; optional decision note |
| submitted → rejected | admin / super_admin (never the applicant) | **reason required**; optional decision note; possible at any time before the decision |
| approved → paid | admin / super_admin (never the applicant) | records the payout (§32) |

- Voting is **advisory only**: no minimum number of votes, no majority rule, no automatic decision. Votes close when the decision is made.
- **Vote counts** (Yes / No) are visible to members; **who voted how** is visible to admin / super_admin only.
- The applicant sees the status, the decision (amount, note or reason) and the payout.
- The committee gets **no access to private profile documents** (§10, §19) — the earlier "during aid review" exception was dropped (owner, Phase 8a).
- Each decision stores who made it and when (decided / cancelled / paid time).

### §31 Approved amount and limits

- The approved amount is whole taka, **any amount ≥ 1** — it may be more or less than the requested amount (owner, Phase 8a).
- **No maximum limits** (no overall or per-reason maximum).

### §32 Disbursement (payout)

- **One payout per case**, always the full approved amount (no instalments).
- Record: **method** (bKash / Nagad / Rocket / Bank / Cash) and **payout date** required; **TxID optional**; **proof file optional** (JPG / PNG / WebP / PDF, max 5 MB, private); recorded by (user).
- Writes a **debit** to the fund ledger (type `disbursement`).
- **Blocked if the current fund balance < approved amount.** Balance for this check = the **full fund ledger** (§34). Demo programmers stay left out of the public / dashboard totals as before.
- Status becomes **paid**; applicant is notified (Phase 10).

---

## H. Transparency & financial integrity (Phase 12)

### §33 Fund Report (members-only)

**Fund Report is members-only** (owner decision, 2026-10-20; replaces the public report of Phase 12a). Visible only to logged-in **approved** accounts (member, committee, admin, super admin) — in the member dashboard menu "Fund Report" (`/dashboard/fund-report`). Not in the public header, mobile menu, footer or sitemap; no fund totals on the Home page or in any public API. Logged out: `/fund-report` redirects to login and returns to the report afterwards. API: `GET /api/fund-report` + `/cases` — 401 if not logged in, 403 if the account is not approved. Content:

- total collected, total disbursed, **current balance**;
- **monthly breakdown** (collected / disbursed per month);
- list of assistance cases: **Case ID, reason, amount, date**.

Privacy:

- Recipient name is **hidden by default**; shown only if the recipient ticked the consent checkbox (§29).
- **Supporting documents are never public** — the applicant, admin and Super Admin only (Phase 8a).
- Contributor-level details (who paid what) are not public. **Decided (owner, 2026-10-16):** no public list of paying members.
- **Built (Phase 12a, made members-only 2026-10-20):** dashboard page `/dashboard/fund-report` (API `GET /api/fund-report` + `/cases`, login + approved only; the old `/api/public/fund-report` endpoints are removed). Only cases with a ledger payout entry are listed; the shown name is the family member named on the request, otherwise the member — only with consent. The consent checkbox is on the aid apply form (unticked by default).

### §34 Financial integrity and audit log

- **Ledger** entry types: `contribution`, `disbursement`, `adjustment`, `reversal`.
- **Balance** = sum of contributions + adjustments − disbursements (reversals cancel the entry they reverse). An aid payout (§32) is checked against this full-ledger balance.
- Financial records can **never be deleted or edited**. Corrections only via a reversal/adjustment entry with a required reason, linked to the original.
- **Audit log** records every financial and approval action: registration approve/reject, role/status change, payment verify/reject, assistance status change (submitted, cancelled, approved, rejected, paid), each vote, approved amount, disbursement, adjustment/reversal, settings change. Each entry: who, when, action, target, old/new values, IP.
- The audit log is append-only and cannot be edited from the app.
- **Built (Phase 12b):** corrections by **Super Admin only** on the Fund Settings page — an adjustment (money in / out, reason required) or a reversal of one entry (reason required; once per entry; a reversal cannot be reversed); confirm box shows the amount and the new balance; never below zero. Home, Fund Report and dashboard show the full ledger balance; the Fund Report shows corrections only as one net total. An aid case becomes *paid* (and a payment *approved*) only together with its ledger entry in one transaction. Read-only integrity check (page section + `php artisan fund:check`) lists mismatches, never fixes them. A test fails if any action above is not audit-logged.

---

## I–K. Chat, notifications & admin

### §35 Chat (Phase 9)

- **1-to-1 chat between approved members** only, realtime via **Laravel Reverb** (port 8080).
- Text messages; conversation list with last message and unread count; read status.
- Private channels; the server checks that the user is a participant and approved.
- Nobody else (including admins) can read private conversations through the app.
- **Block + report — Decided (owner, Option A, Phase 9d):**
  - **Block:** a member can block another member from the chat menu and unblock them later ("Blocked members" list). While a block exists, **neither side can send** (API 403); old messages stay readable. The blocker sees "You blocked this member" + Unblock; the blocked member only sees a neutral "You can't reply to this conversation" (they are not told they were blocked).
  - **Report:** a member can report **one message they received** (reason: harassment / spam / fraud or asking for money / other, optional note ≤ 500), once per message, at most 10 reports per 24 hours. Reporting does not block. Admins / Super Admin see the reports on the **Chat Reports** page — only a **text copy of the reported message**, the reason, the note and both members — and mark each one **reviewed** or **dismissed** (audit log, without message text). Admins still cannot open any conversation.
- **Unavailable partner (Phase 9d):** if the other member is suspended, pending, removed (rejected), a demo profile or their account no longer exists, sending is refused (API 403, "This member is not available for messages right now"). The chat screen hides the send box and shows a calm notice; old messages stay readable; Report and Block still work. The chat list keeps the conversation with a small **"Unavailable"** label (a missing account shows as "Former member"). A member who is suspended themselves is logged out at the next request and cannot open or send chats (same as the rest of the app, §11).
- Message sending is rate-limited.
- Built in sub-steps:
  - **9a** — Reverb setup + conversations/messages API + permissions
  - **9b** — chat UI (conversation list, message screen, "Message" button on profiles)
  - **9c** — realtime delivery, unread counts, read status
  - **9d** — block + report, unavailable partners, English + phone-width check

### §36 Notifications and PWA (Phase 10)

- **In-app notifications** (bell with unread count, list, mark as read) + **web push** (VAPID), sent in the user's chosen language.
- **Install prompt** for the PWA (Android/desktop); iOS hint: push works only on iOS 16.4+ after "Add to Home Screen".
- Members can turn push on/off.

| Event | Who is notified |
|---|---|
| Registration approved / rejected | the member |
| Payment verified / rejected | the member |
| Assistance status changed | the applicant |
| New aid request open for voting | everyone who may vote (§30) |
| New notice published | all approved members |
| New chat message | the recipient |

**Decided (owner, 2026-10-16 — Phase 10 plan):**

- Phase 10 sub-steps: **10a** in-app notifications (bell, list, mark read, live update) · **10b** service worker + install prompt (Android / desktop) + iOS "Add to Home Screen" hint · **10c** web push (VAPID, member on/off switch, stored language for push text).
- Only **approved, non-demo** accounts receive notifications. A **rejected registration gets no notification** (they cannot log in; the reason is shown at login). Re-approving a suspended / rejected member sends the "approved" notification again.
- **New chat messages are not in the bell** (chat has its own unread badge) — they come only as web push in 10c.
- New notice → all approved members **except the admin who published it**; only **once** per notice (unpublish + publish again does not repeat it); a notice scheduled for later notifies when its date comes. Existing notices at the time 10a was installed do not notify.
- New aid request → everyone who may vote (§30), not the applicant. Assistance status changes notify the applicant on approve, reject and payout (not on the applicant's own cancel).
- In-app texts are built when read, in the language the member is using at that moment.
- **10b defaults (2026-10-16):** installed app name "JPPHAF", opens at `/dashboard`, navy theme `#021d30`. The service worker caches **only static files** (JS / CSS / fonts / icons) + an offline page — never pages, API answers, login, private files, chat or notifications. Install button / iOS hint only inside the dashboard (phones: in the account menu); "not now" hides them for **14 days**. A new service-worker version shows an "Update available — Reload" toast (no forced reload).

### §37 Admin panel (Phase 11)

| Section | Contents |
|---|---|
| Dashboard | balance, collected/disbursed this month, pending registrations, pending payments, open assistance cases, votes needed by me |
| Members | list/search/filter, approve/reject, view profile, role assignment (Super Admin) |
| Payments | pending verification queue, verify/reject, history, dues per member |
| Assistance + voting | cases by status, case detail with documents, vote, approved amount, disbursement |
| Content | hero slides, featured programmers (§13), Home expertise cards (§13), gallery + categories, notices, About page, machine/skill lists |
| Contact messages | list, read/unread |
| Settings (Super Admin) | monthly amount + effective month, fund payment numbers (quorum / per-reason limits dropped — see Decided below) |
| Site Settings (admin + super_admin, Phase 11c) | contact info, About text (bn / en), committee positions + order on About |
| Audit log | searchable, filter by user/action/date |

Phases 3–8 may build the minimal admin screens they need; Phase 11 completes and unifies the panel. All admin sections live inside the dashboard shell of §37a.

**Decided (owner, 2026-10-16 — Phase 11 plan):**

- Sub-steps: **11a** Contact Messages + Audit Log pages · **11b** hero slides admin + Featured Programmers · **11c** site contact info, About page text, automatic committee list · **11d** machine / skill lists, Content hub, final review of the whole panel.
1. **Audit log:** admin + super_admin only, **read-only** (no edit / delete anywhere).
2. **Contact messages:** admin + super_admin read them and mark them read / unread; **only super_admin deletes**; a delete is written to the audit log **without the message text**.
3. **About → committee list** is built automatically from users with the `committee` role; it shows **name, photo and position only — never mobile numbers**.
4. **Quorum and per-reason limits are dropped** (removed in Phase 8 — the "Settings" row above no longer includes them).
5. A machine / skill **removed** from the admin list is hidden from new choices, but **existing profiles keep it**.
6. **Content hub (11d-2):** the sidebar has one **Content** item (admin + super_admin); the content pages (hero slides, featured programmers, expertise cards, gallery, notices, Site Settings, machine / skill lists) open from cards on `/dashboard/content` instead of being separate sidebar items.

### §37a Dashboard (Admin & Member Panel)

One dashboard for all logged-in approved users; what each person sees depends on their role. Added by the owner on 2026-10-13.

**1. Layout**

- Professional dashboard with a **fixed vertical sidebar on the left**; it can be collapsed. On mobile it becomes a **slide-in drawer**.
- Sidebar menu, in this order: **Dashboard, Programmers List, Payments/Dues, Aid Applications, Committee Voting, Content, Contact Messages, Audit Log, Settings, My Profile.**
- Each role sees **only the menu items it has permission for** (see access below and §10). All labels in bn/en.
- Uses the design system of **§8a** (teal gradient, blue, dark navy, Poppins + Inter + Hind Siliguri, 16 px cards, pill buttons) — the same visual style as the public pages.

**2. Access**

| Role | What they see |
|---|---|
| `admin` / `super_admin` | Full data for **all members** |
| `committee` | Views all members' data, **read-only** (no edits) |
| `member` | **Only own data** — own summary + own payment history |

The server enforces this on every API route (§11, §38); the sidebar only hides items.

**3. Dashboard summary cards**

Total collected · collected this month · total outstanding dues · total aid disbursed · current balance · active members count · pending registrations count. (A `member` sees only their own summary.)

**4. Programmers List**

- Table of all **approved** programmers: photo, name, mobile, area/factory (district + workplace from the profile, §19), status, total paid, total due.
- Search, filter by status and by dues, pagination.
- Clicking a programmer opens the **programmer detail** page.

**5. Programmer detail (payment history)**

- Month-by-month table from the **fund start month to the current month** (§25, changed in Phase 7d): month, **paid / unpaid** (clear coloured badge), amount, payment date, method (bKash / Nagad / cash), reference.
- Totals: total paid, total due, months unpaid.
- Year filter.
- A `member` sees this page only for themselves.

**6. Phasing**

| Part | Phase |
|---|---|
| Dashboard layout + sidebar + profile pages (My Profile, programmer detail shell, Programmers List without payment columns). Cards/pages with no data yet show a **"coming soon"** placeholder | **4b** |
| Summary card numbers, Programmers List payment columns (total paid / total due), programmer payment history | **7** (fund contributions) |
| Aid Applications, Committee Voting | 8 |
| Content, Contact Messages, Audit Log, Settings (full screens) | 11 |

---

## L. Security & non-functional

### §38 Security and non-functional requirements

- **Auth:** Laravel Sanctum.
- **Authorisation:** role + status checks on **every API route** (middleware + policies), never only in the UI.
- **Rate limiting:** login, register, contact form, payment submission, chat messages.
- **File uploads:** validate type and size; random file names. Limits — **Decided:** images JPG/PNG/WebP up to **2 MB**, documents PDF/JPG/PNG up to **5 MB**.
- **Private storage:** assistance documents, payment screenshots and disbursement proofs are stored outside the public folder and served only through authorised API routes.
- **Secrets** only in `.env` files.
- **Backup:** daily database backup script (Phase 13), dumps kept in `database/backups/`; retention period **TBD (owner decides)** — default 30 days.
- **Performance:** mobile-first, optimised images, paginated lists.
- **Privacy:** public pages never expose hidden contact data, pending users, documents or private chats.
- **Hosting / domain / SMS provider:** decided later, in Phase 13 — **Decided** (deferred).

---

## §39 Build plan (13 phases — approved)

| Phase | Scope |
|---|---|
| 1 | Design system (header/footer, colors, fonts, bn/en toggle) |
| 2 | Public pages: Home (hero background image slider), About, Contact |
| 3 | Registration/login + admin approval |
| 4 | 4a: Own profile edit (photo, experience, machines, portfolio) + new fields: employment status, designation, work history, portfolio category/title/description (§19); 4b: align design system with the screenshots (§8a tokens, header, footer) + Dashboard layout + left sidebar + profile pages, placeholders for data not built yet (§37a) |
| 5 | Programmers directory + filters + public profile — to match `programmers.png` / `programmer-profile.png` (§21–§22) |
| 6 | Gallery (`gallery.png`, categories Programs/Events/Training/Community/Workplace) + Notices + Home and About rebuilt to match `home.png` / `about.png` (§13–§14) |
| 7 | Fund contributions (payment submit, admin verify, ledger, dues) + dashboard summary numbers, Programmers List payment columns, payment history (§37a) |
| 8 | Assistance requests (apply, advisory voting, admin decision, payout) |
| 9 | Chat via Reverb (9a, 9b, …) |
| 10 | Notifications + PWA push/install |
| 11 | Admin panel |
| 12 | Transparency report + security hardening |
| 13 | Final testing, backup, deployment prep |

### Phase 13 go-live checklist

- [ ] Replace demo Super Admin credentials (01700000000 / admin@jpphaf.local) with real ones and change the password before going live.
