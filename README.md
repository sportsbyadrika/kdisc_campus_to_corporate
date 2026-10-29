# Campus to Corporate (C2C) — Institutional Tracking Portal

A PHP / MySQL / Tailwind CSS v4 portal to track the counts and progress of Kerala's **Campus to Corporate** programme. It covers every institution across the 14 districts. The UI is built from the `campus_to_corporate_onboarding.html` design: the 6-step institution workspace and the institution dashboard.

Page URLs have no `.php` extension (`/dashboard`, `/reports?district=7`, `/institution-profile?id=12`).

## Roles

| Role | Access |
|---|---|
| **Super Admin** | Full access. Hidden from every other user (not shown in user lists, and shown as "System" in activity logs). Created only by the installer. Only the Super Admin can **permanently delete an institution** (bin icon next to Deactivate on *Institutions*), meant for the initial data clean-up. This removes its profile, officers, student strength, cohorts, assessments, services, vendor counts, enrollment requests, uploaded images, and institution logins assigned to no other institution. |
| **Administrator** | Settings, all masters, user management (all roles except super admin), all reports, all institutions. |
| **State user** (Kerala) | All institutions; State → District → Institution drill-down reports; adds new institutions (directly or by approving district requests); global masters (universities, categories, courses, assessment tests, DWMS services, district support team). |
| **District user** (×14) | Institutions of their district and its district-wise / institution-wise statistics; updates institution data; requests new institutions from the State; creates institution users and assigns each one to one or more institutions. |
| **Institution user** | Assigned institution(s) only: profile, placement officers, student strength, cohorts & assessments, DWMS services and the institution dashboard. A user mapped to several institutions gets a switcher. |
| **State-level Entity** | View-only: Dashboard, Reports and My Account. Sees a *group* of institutions defined by filters instead of individual institutions — districts (or all), universities, institution types and management types; an institution must match every restricted group. Example: a university = all districts, all types, all management types, with that university ticked. Can open the dashboard of an in-scope institution and export report CSVs. |
| **District-level Entity** | Same as a State-level Entity, but always limited to one district. |

Every user also has optional **Designation** and **Name of Institution** fields (users form and My Account). Entity users are created by the Administrator under Users.

## Features

- **Institution workspace** (stepper from the design):
  1. *Profile*: affiliated university and category (global masters), address, latitude/longitude picked on an **OpenStreetMap** map (Leaflet + Nominatim search), logo, campus photo and an optional history.
  2. *Placement officers*: one or more, with one marked as the nodal officer.
  3. *Course / department-wise student strength* for each academic year, with a copy-from-last-year helper.
  4. *Cohorts & assessments*: final-year total, DWMS registered, immediate job seekers and higher-studies aspirants. The assessment tests come from the masters, filtered by the institution's university. One test can be marked as the mandatory gateway.
  5. *DWMS services*: each service has a status, the number of students who benefited, a date and remarks.
  6. *Dashboard*: identity banner, statewide rank and readiness score, the district support team (TCE / RPM / Regional Head), KPIs, a live milestone tracker, a list of next tasks, a cohort trend chart, a course breakdown, a map and recent changes.
- **Drill-down reports**: Kerala → the 14 districts → the institutions of a district. Every signed-in user can view them read-only; an institution row opens its dashboard only for users who manage that institution. You can filter by university and category, print, and (Admin, State and District users) export to CSV.
- **Bulk upload of institutions** (*Institutions → Bulk upload*, Administrator and Super Admin). Download the CSV template and the reference list of valid districts, universities, categories and types. Upload the filled file to see a preview: each row is marked *Ready* or *Issue*, with the reasons. Rows can have issues because a required field is missing, a master value is unknown, an email/mobile/pincode/year/location is invalid, the DWMS ID is already used, the institution already exists in the district, or the row repeats another in the file. *Import* adds only the ready rows (re-checked at import). *Download issue records* gives the problem rows with an *Issues* column; correct them and upload that file again. Only new institutions are added; existing ones are never changed.
- **Campus-placed students**: recorded on the Cohorts & Tests page for the current academic year and the previous one, with the change shown alongside and in reports.
- **DWMS Institution ID**: a unique ID per institution, set by the Administrator / State (Institutions) or the District office (profile). It is used to match vendor uploads.
- **Vendor test counts** (*Test Upload*, Admin and District users): download a CSV template listing institutions by DWMS Institution ID with one column per assessment test, fill in the tests-conducted counts from the test vendor's dashboard and upload it. The figures appear read-only as *Tests Conducted (Vendor)* next to each test on the Cohorts & Tests page, compared with the institution's own field figure.
- **Change tracking**: every change is written to `activity_log` with old and new values, viewable under **Settings → Activity log** (District and State users open it from *View all* on their dashboard). Cohort figures are also saved as snapshots, which feed the trend charts.
- **Readiness score** = 30% DWMS coverage + 50% gateway-assessment coverage of job seekers + 20% onboarding completion.
- **Security**: CSRF tokens, prepared statements, `password_hash`, session regeneration, login throttling, role- and district-scoped access checks on every page. Uploaded images are re-encoded through GD, and script execution is blocked in `uploads/`.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `openssl`
- MySQL 8.0+ or MariaDB 10.6+
- Apache with `mod_rewrite` (the `.htaccess` file hides `.php`), or nginx (see below)
- Node 18+ **only** to rebuild the CSS. The compiled `assets/css/app.css` is committed.

## Installation

```bash
# 1. Database credentials and settings
cp .env.example .env    # then edit it (real env vars or app/config.local.php also work)

# 2. Create tables, masters, the super admin and the administrator
php database/install.php --superadmin-password='…' --admin-password='…'
#    add --demo to load sample institutions and demo users (password Demo@2026)

# 3. Point the web server's document root at this folder
```

When no passwords are given, the installer generates them and prints them. If you can't use the CLI, you can import `database/schema.sql` with phpMyAdmin, but you still need to create the user accounts with `install.php`.

**Local development:**

```bash
php -S localhost:8000 router.php     # router.php mirrors the .htaccess rules
npm install && npm run watch         # only when you change Tailwind classes
```

**nginx:**

```nginx
location ~ ^/(app|database|node_modules)/ { deny all; }
location ~ ^/uploads/.*\.php$ { deny all; }
location / { try_files $uri $uri/ $uri.php?$args; }
location ~ \.php$ {
    if ($request_uri ~ \.php) { rewrite ^/(.*)\.php$ /$1 permanent; }
    include fastcgi_params; fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

If the portal is served from a sub-folder, set `base_path` (for example `/c2c`) in the config.

## Deploying on cPanel (c2c.kdiscmis.org.in)

`.cpanel.yml` deploys the repository at `/home/shooting/repositories/kdisc_campus_to_corporate` to the web root `/home/shooting/public_html/c2c.kdiscmis.org.in`.

1. **Database:** in cPanel → *MySQL Databases*, create a database and a user (for example `shooting_c2c`), and give the user *All privileges* on the database.
2. **Repository:** in cPanel → *Git Version Control*, clone the GitHub repository into `/home/shooting/repositories/kdisc_campus_to_corporate`.
3. **Deploy:** click *Manage → Pull or Deploy → Update from Remote*, then *Deploy HEAD Commit*. `.cpanel.yml` runs `deploy/cpanel-deploy.sh`, which copies the code into the web root and removes files that were deleted from the repo. It never touches `.env`, `app/config.local.php` or uploaded logos and photos.
4. **`.env`:** on the first deploy only, use *File Manager* or *Terminal* to copy `.env.example` from the repository into the web root as `.env`, then fill in the database values:
   ```bash
   cp ~/repositories/kdisc_campus_to_corporate/.env.example ~/public_html/c2c.kdiscmis.org.in/.env
   nano ~/public_html/c2c.kdiscmis.org.in/.env
   ```
   The deploy sets `.env` to mode 640, and `.htaccess` blocks web access to it.
5. **Install:** in cPanel *Terminal*, run this once (use your PHP version's binary if `php` isn't 8.1+, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`):
   ```bash
   cd ~/public_html/c2c.kdiscmis.org.in && php database/install.php --superadmin-password='…' --admin-password='…'
   ```
6. **PHP settings:** in *MultiPHP Manager*, select PHP 8.1 or newer for the domain, with the `pdo_mysql`, `gd`, `fileinfo` and `mbstring` extensions enabled.

For later releases, push to GitHub, then *Update from Remote* and *Deploy HEAD Commit*. cPanel will only deploy when the repository's working tree is clean.

**If the files don't update after a deploy:**
- Run *Update from Remote* before *Deploy HEAD Commit*. The deploy only copies the commit that is already checked out in `~/repositories/kdisc_campus_to_corporate`.
- Check the *Basic Information* panel: the checked-out branch should be `main`, and *HEAD Commit* should match the latest commit on GitHub.
- Read the deploy logs: `~/c2c-deploy.log`, written by `deploy/cpanel-deploy.sh`, and cPanel's own logs in `~/.cpanel/logs/` (files named `vc_*_git_deploy.log`).
- Run the deploy by hand in *Terminal* to see any errors directly:
  `cd ~/repositories/kdisc_campus_to_corporate && bash deploy/cpanel-deploy.sh`

## Institution self-enrollment

The sign-in page has an **Enroll my Institution** button (`/enroll`). An institution picks its district and then itself from the list, and enters the Head of the Institution and the Placement Officer (names, emails, mobile numbers).

- **One request per institution.** This is enforced by a unique database key. An institution can apply again only after a rejection. Institutions that are already enrolled, or that have a pending request, are shown but can't be selected.
- **Validation.** The Head and the Placement Officer must have different email addresses and mobile numbers. The Placement Officer's email must not already belong to a portal user.
- **Bot and abuse protection.**
  - CSRF token and a self-hosted CAPTCHA image (`/captcha`, single use).
  - A hidden honeypot field and a minimum time to fill in the form.
  - Per-IP rate limits: 15 submissions an hour, 5 successful requests a day, and limits on CAPTCHA images and institution lookups.
  - Strict server-side validation with length limits.
  - A strict Content-Security-Policy on all public pages (no inline scripts).
- **Review.** District users review requests under **Enrollments** (with a pending-count badge). Administrators can act on any district; State users can view only.
  - **Approve** creates an institution login for the Placement Officer (username from their email; they can also sign in with the email). It assigns the login to the institution and stores the Head and Officer details. The Placement Officer is emailed a one-time **set-password link**, valid for 72 hours. The Head receives a confirmation email **without** any login link.
  - **Reject** requires a comment, which is emailed to both contacts.
  - **Resend login email** issues a new link and cancels the old ones.
- **Forgot password?** on the sign-in page emails a one-time reset link. The reply is the same whether or not the account exists. Password links are stored only as SHA-256 hashes.

### Email (PHPMailer)

Emails are sent with [PHPMailer](https://github.com/PHPMailer/PHPMailer) 6.12. It is committed in `vendor/`, so the server needs no Composer run. To update it, run `composer update` locally and commit `vendor/`. Configure email in `.env` (see `.env.example`):

| Setting | Purpose |
|---|---|
| `C2C_APP_URL` | Public address used in email links, e.g. `https://campustocorporate.kdisc.in` |
| `C2C_MAIL_DRIVER` | `mail` (server mail), `sendmail`, `smtp` (recommended) or `log` (testing; nothing is sent) |
| `C2C_MAIL_FROM`, `C2C_MAIL_FROM_NAME` | Sender, e.g. a `no-reply@` mailbox created in cPanel → Email Accounts |
| `C2C_SMTP_HOST`, `C2C_SMTP_PORT`, `C2C_SMTP_SECURE`, `C2C_SMTP_USER`, `C2C_SMTP_PASS` | SMTP details from cPanel → Email Accounts → Connect Devices (usually port 465 with `ssl`) |

Every email is recorded in the `mail_log` table. Administrators can see recent deliveries and any errors under **Settings → Recent emails**. For good deliverability, make sure SPF and DKIM are enabled for the domain (cPanel → Email Deliverability).

## Public home page

`/` is the public landing page, built to the client's design. It has these sections:

- a hero with the targets and *Enroll Your Institution*
- *A Stronger Bridge* / *Why It Matters?*
- the seven-step *Journey*
- two banners around *Latest Updates & Resources*
- FAQs
- a footer with quick links and contact details

`/updates` lists every update. Signed-in users see *Go to Dashboard* instead of *Institution Login*.

Administrators manage it under **Settings → Home page**:

- **Hero style:** *Light* (white background with dark navy and teal labels, for a photo on a white background; the default) or *Dark* (navy background with white labels).
- **Texts and links:** hero wording, the two targets, the handwritten tagline and the About paragraph. The small heading, the targets note and the tagline are hidden when left empty. Also the contact email and location, plus the social and policy links. Social icons and policy links show only when a link is set.
- **Images:**
    - the hero photo (people on the right half)
    - the two banner photos
    - the Government of Kerala, K-DISC and KKEM logos

  Until each is uploaded, a drawn stand-in is shown. Images are stored in `uploads/branding`; deployment never overwrites them.
- **Latest Updates & Resources:** a title, a date and either a link or a PDF (stored in `uploads/updates`). Each can be shown or hidden. The newest three appear on the home page.

## Online Exams (Activity menu)

The **Activity** menu holds programme activity modules (more can be added as cards on `activity.php`). It is shown to Administrators and to anyone made a co-admin of an exam.

- **Privacy.** An exam is visible only to the Administrator who created it, the co-admins they add (*Co-admins* tab, any active Administrator, State, District or Institution user) and the Super Admin. Only the creator manages co-admins or deletes the exam.
- **Exam settings.** Name, starting and ending period (the cut-off), duration in minutes, status (Draft / Active / Closed), shuffling of questions and options, and instructions.
- **Master question bank.** Objective single selection, objective multiple selection and descriptive questions with marks, options and correct answers. Add one at a time or bulk-paste (`*` correct option, `-` other option, `marks: 2`, blank line between questions).
- **Question series.** Sets of questions taken only from the master bank, picked by hand, by "pick N at random", or generated in bulk (N series × questions per type). Each participant starting the exam gets the least-used series, so papers are spread evenly. Without series, everyone gets the whole bank. The bank and series lock once anyone has started.
- **Participants.** Name, email, mobile and details, added one by one or pasted from Excel/CSV. *Send exam passwords* emails each ticked participant a new random password with the expiry you choose; only its hash is stored.
- **Taking the exam** (`/exam-login`, then `/exam-portal`). The participant reads the instructions and presses *Start exam*. The deadline is the duration, capped at the cut-off, and it is enforced on the server; the on-screen clock auto-submits at zero. Questions come one at a time; the server accepts only the answer to the current question, so there is no going back (even with the browser Back button). Copy, cut, paste, drag and the context menu are blocked, and switching tabs is counted. *Sign out* ends the attempt. A dropped connection or closed browser does not: signing in again resumes the same question until the deadline. Signing in on another device signs out the first one.
- **Results.** Objective answers are marked automatically (a multiple-selection answer must match the correct set exactly). Descriptive answers are marked on each participant's answer sheet. Participants who never started before the cut-off are **Absent**. Everyone else is **Completed** with a remark: normal completion, time out or logged out. Results can be published (optionally emailing each score) once every participant is Completed or Absent and all answers are marked. Participants then see their score when they sign in. CSV export is available.

## Database upgrades

The portal upgrades an existing database automatically: on the first page load after a deploy it applies any pending schema changes (tracked in `settings.schema_version`, see `app/migrations.php`). The changes only add columns and tables, never remove data. Running `php database/install.php` again does the same.

## Project layout

```
*.php                 pages (served without the extension)
app/                  bootstrap, config, auth & scoping, metrics, charts, layout (web access denied)
assets/css/input.css  Tailwind v4 source → assets/css/app.css (built)
assets/js/            UI behaviour and the map picker
database/             schema.sql + install.php (web access denied)
uploads/              institution logos and photos (no script execution)
```

## Masters

These are managed by the State user or the Administrator under **Masters**: universities, institution categories, institution types (Engineering College, Arts & Science College, Polytechnic, … — pre-filled and editable), courses/streams, assessment tests (per university, or global when the university is blank; one can be the mandatory gateway), DWMS services, and the district support team. The current **academic year** is set under **Settings**. Student strength, cohorts and assessments are stored per academic year, so earlier years are kept.
