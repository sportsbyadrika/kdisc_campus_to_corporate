# Campus to Corporate (C2C) — Institutional Tracking Portal

A PHP / MySQL / Tailwind CSS v4 portal to track the counts and progress of Kerala's **Campus to Corporate** programme. It covers every institution across the 14 districts. The UI is built from the `campus_to_corporate_onboarding.html` design: the 6-step institution workspace and the institution dashboard.

Page URLs have no `.php` extension (`/dashboard`, `/reports?district=7`, `/institution-profile?id=12`).

## Roles

| Role | Access |
|---|---|
| **Super Admin** | Full access. Hidden from every other user (not shown in user lists, and shown as "System" in activity logs). Created only by the installer. |
| **Administrator** | Settings, all masters, user management (all roles except super admin), all reports, all institutions. |
| **State user** (Kerala) | All institutions; State → District → Institution drill-down reports; adds new institutions (directly or by approving district requests); global masters (universities, categories, courses, assessment tests, DWMS services, district support team). |
| **District user** (×14) | Institutions of their district and its district-wise / institution-wise statistics; updates institution data; requests new institutions from the State; creates institution users and assigns each one to one or more institutions. |
| **Institution user** | Assigned institution(s) only: profile, placement officers, student strength, cohorts & assessments, DWMS services and the institution dashboard. A user mapped to several institutions gets a switcher. |

## Features

- **Institution workspace** (stepper from the design):
  1. *Profile*: affiliated university and category (global masters), address, latitude/longitude picked on an **OpenStreetMap** map (Leaflet + Nominatim search), logo, campus photo and an optional history.
  2. *Placement officers*: one or more, with one marked as the nodal officer.
  3. *Course / department-wise student strength* for each academic year, with a copy-from-last-year helper.
  4. *Cohorts & assessments*: final-year total, DWMS registered, immediate job seekers and higher-studies aspirants. The assessment tests come from the masters, filtered by the institution's university. One test can be marked as the mandatory gateway.
  5. *DWMS services*: each service has a status, the number of students who benefited, a date and remarks.
  6. *Dashboard*: identity banner, statewide rank and readiness score, the district support team (TCE / RPM / Regional Head), KPIs, a live milestone tracker, a list of next tasks, a cohort trend chart, a course breakdown, a map and recent changes.
- **Drill-down reports**: Kerala → the 14 districts → the institutions of a district → an institution dashboard. You can filter by university and category, export to CSV or print.
- **Change tracking**: every change is written to `activity_log` with old and new values. Cohort figures are also saved as snapshots, which feed the trend charts.
- **Readiness score** = 30% DWMS coverage + 50% gateway-assessment coverage of job seekers + 20% onboarding completion.
- **Security**: CSRF tokens, prepared statements, `password_hash`, session regeneration, login throttling, role- and district-scoped access checks on every page. Uploaded images are re-encoded through GD, and script execution is blocked in `uploads/`.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`
- MySQL 8.0+ or MariaDB 10.6+
- Apache with `mod_rewrite` (the `.htaccess` file hides `.php`), or nginx (see below)
- Node 18+ **only** to rebuild the CSS. The compiled `assets/css/app.css` is committed.

## Installation

```bash
# 1. Database credentials: environment variables or app/config.local.php
cp app/config.local.example.php app/config.local.php   # then edit it

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

These are managed by the State user or the Administrator under **Masters**: universities, institution categories, courses/streams, assessment tests (per university, or global when the university is blank; one can be the mandatory gateway), DWMS services, and the district support team. The current **academic year** is set under **Settings**. Student strength, cohorts and assessments are stored per academic year, so earlier years are kept.
