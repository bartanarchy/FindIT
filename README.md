# FindIT — Campus Lost and Found

FindIT is a web-based Lost and Found information system for the campus community of the Information Technology department (JTI, Polinema). Students report items they found, search for belongings they lost, and submit ownership claims. Administrators verify each claim before an item is handed over.

The platform centralizes information that used to be scattered across chat groups and front desks, and gives every handover a documented process.

## Features

### Students

* Register with a campus email (`NIM@student.polinema.ac.id`). The 12-digit NIM must match the Student ID, and a captcha protects the form.
* Sign in, sign out, and reset a forgotten password through an email link (valid for 30 minutes, single use).
* Browse, search, and filter the found items on the homepage.
* Open an item to see its details. The item's secret characteristic is never shown publicly.
* Report a found item.
* Claim an item by describing it (where it was lost, where it was last seen, and its specific characteristics).
* Follow claims in **My claims**. An approved claim shows a pickup code and the pickup location.
* Receive notifications through a bell icon that updates automatically.
* Manage a profile: upload a photo (JPG, PNG, or WebP, up to 2 MB) or choose one of 8 built-in avatars, and change the password.

### Administrators

* Dashboard with a summary of items and claims.
* Add, edit, search, and filter item reports.
* Review each claim and approve or reject it with a note. Approving a claim marks the item as claimed and automatically rejects the other pending claims for that item.
* View registered users.
* Create and restore database backups from the admin panel.
* Receive live alerts for new activity.

### Security and reliability

* Prepared statements for every query, CSRF tokens on every form, and escaped output.
* Passwords are hashed with `password_hash()`.
* Sensitive columns (names, emails, phone numbers, NIM, item details, claim answers, notes, notification text, and profile photos) are encrypted with AES-256-GCM. Lookups by email and NIM use blind indexes.
* Password reset tokens are stored only as hashes.
* Folders that must stay private (`app`, `config`, `database`, `scripts`) and files such as `.env` are blocked from direct web access with `.htaccess`.
* Automatic and manual backups with restore tests, and a maintenance page when the database cannot be reached.

## Project Status

**Current stage:** core features complete. The team is now testing and hardening the system.

### Completed

* [x] PostgreSQL database, tables, and relationships.
* [x] Homepage with search and category filter.
* [x] Item detail page.
* [x] Registration, login, logout, and session management with password hashing.
* [x] Campus email and NIM validation, and registration captcha.
* [x] Forgot and reset password by email.
* [x] Found-item reporting.
* [x] Claim submission, claim status, and claim details.
* [x] Admin dashboard, item management, and claim verification.
* [x] Student notifications and admin alerts.
* [x] Student profile with photo or avatar.
* [x] Data encryption and blind indexes.
* [x] Backup and restore system.
* [x] Responsive interface.

### Planned

* [ ] Limit repeated login and password-reset attempts.
* [ ] Stricter session cookie settings.
* [ ] Different access levels for administrators.
* [ ] New FindIT logo across the interface.
* [ ] Final testing with clean demonstration data.

## Tech Stack

| Technology | Purpose |
| --- | --- |
| PHP 8.1+ | Backend development |
| PostgreSQL | Relational database |
| PDO | Database connection and prepared statements |
| Bootstrap 5 | Responsive interface (served locally from `public/assets/vendor`) |
| HTML5, CSS3 | Page structure and custom styling |
| Vanilla JavaScript | Client-side interactions |
| PHP GD | Registration captcha and profile photo processing |
| OpenSSL (PHP) | AES-256-GCM encryption |
| Mailpit or Gmail SMTP | Password reset emails during development |
| Laragon | Local development environment |
| Git and GitHub | Version control and team collaboration |

## Project Structure

```text
FindIT/
├── admin/                      Administrator pages
│   ├── add-item.php
│   ├── alerts-api.php
│   ├── backups.php
│   ├── claim-detail.php
│   ├── claims.php
│   ├── edit-item.php
│   ├── index.php
│   ├── items.php
│   └── users.php
├── app/
│   ├── backup/                 Backup and restore engine
│   ├── controllers/
│   │   └── AuthController.php  Register, login, logout, password reset
│   ├── helpers/                Shared tools (auth, csrf, crypto, ui, mailer,
│   │                           notifications, captcha, avatar, and others)
│   └── models/                 Admin.php, PasswordReset.php, User.php
├── config/
│   └── config.php              Reads .env and opens the database connection
├── database/
│   ├── hardening/              least-privilege.sql
│   ├── migrations/             001 to 008 (numbered, run in order)
│   ├── schema.sql              Base tables
│   └── seed.sql                Categories and the initial admin account
├── public/                     Pages and assets that visitors can reach
│   ├── assets/                 css, js, images, vendor
│   ├── avatar.php, captcha.php, claim.php, forgot-password.php,
│   ├── index.php, item-detail.php, login.php, logout.php, my-claims.php,
│   ├── notifications.php, notifications-api.php, profile.php,
│   └── register.php, report-item.php, reset-password.php
├── scripts/                    Command-line tools (keys, backup, restore)
├── .env.example                Template for the local .env file
├── .htaccess                   Blocks .env and .git from the web
└── README.md
```

## Database

The base tables are in `database/schema.sql`. Features added later live in numbered files in `database/migrations/`.

| Table | Purpose |
| --- | --- |
| `"User"` | Student accounts (note the quotes: the table name starts with a capital letter) |
| `admin` | Administrator accounts and access levels |
| `category` | Item categories |
| `location` | Item discovery locations |
| `item` | Reported found items |
| `claim` | Ownership claims and verification results |
| `notification` | Student notifications |
| `password_resets` | Password reset tokens (hashed, expiring, single use) |
| `pickup_token` | Pickup codes for approved claims |
| `user_photo` | Profile photos and chosen avatars |

Status values are stored as exact English text and are case-sensitive:

* Item: `Found`, `Claimed`
* Claim: `Pending Verification`, `Approved`, `Rejected`

### Migrations

| File | Purpose |
| --- | --- |
| `001_create_notification_table.sql` | Notification table |
| `002_security_encryption.sql` | Encrypted columns and blind indexes |
| `003_one_claim_per_user_per_item.sql` | One claim per student per item |
| `004_pickup_qr.sql` | Pickup codes |
| `005_claimed_on_approval.sql` | Approved claim marks the item as `Claimed` |
| `006_password_resets.sql` | Password reset tokens |
| `007_notifcation_upgrade.sql` | Notification types, links, and de-duplication |
| `008_user_profile_photo.sql` | Profile photo and avatars |

The migrations are written to be safe to run more than once. The database is not shared through Git, so **every team member runs the migrations on their own PostgreSQL**. If `003` fails with "could not create unique index", the local data contains duplicate claims from the same student for the same item. Remove the extras and run it again.

## Getting Started

### Requirements

* Windows with Laragon (Apache) and PHP 8.1 or later
* PostgreSQL and pgAdmin
* PHP extensions: `pdo_pgsql`, `pgsql`, `gd`, `openssl`, `mbstring`
* Git

### 1. Clone the repository

Open a terminal in the Laragon `www` folder:

```bash
git clone <repository-url>
cd FindIT
```

### 2. Create the database

In pgAdmin, create an empty database named `FindIT`.

### 3. Configure the environment

Copy `.env.example` to `.env`, then fill in your values.

```text
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS   your local PostgreSQL settings
APP_ENC_KEY                                   encryption key (see below)
APP_URL                                       http://localhost/FindIT/public
MAIL_*                                        email settings (see below)
```

Generate the encryption key:

```bash
php scripts/generate-key.php --write
```

Important about `APP_ENC_KEY`:

* It is not stored in Git. **Everyone who shares data must use the same key**, otherwise encrypted data cannot be read.
* If the key is lost, the encrypted data and the backups cannot be recovered. Keep a safe copy and share it privately, never through GitHub.

Never commit `.env`.

### 4. Create the tables

**New, empty database:** run these files in pgAdmin's Query Tool, in this order:

1. `database/schema.sql`
2. `database/seed.sql`
3. every file in `database/migrations/`, from `001` to `008`

**Existing database:** run only the migrations you have not run yet. Do not run `schema.sql` again.

`seed.sql` adds the item categories and the initial administrator account (`findit@gmail.com`). Ask the team for the initial password and change it before any demonstration.

### 5. Enable the PHP extensions

In Laragon open the PHP menu, then Extensions, and make sure `gd`, `pdo_pgsql`, `pgsql`, `openssl`, and `mbstring` are enabled. Restart Apache afterwards.

### 6. Run the application

Start Apache and PostgreSQL in Laragon, then open:

```text
http://localhost/FindIT/public/
```

Register a student account with an email like `123456789012@student.polinema.ac.id`, where `123456789012` is a 12-digit NIM, and enter the same NIM as the Student ID. Sign in as the administrator with the seeded admin account to use the admin panel.

### 7. Email for password reset

Choose one option in `.env`:

* **Mailpit (local, nothing is really sent):** `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, and leave the rest empty. Run Mailpit, then read the emails in its web page.
* **Gmail SMTP:** `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, your Gmail address as `MAIL_USERNAME` and `MAIL_FROM_ADDRESS`, and a Google App Password as `MAIL_PASSWORD`.

The reset link points to `APP_URL`, so it only opens on the computer that runs the server.

## Backup and Restore

The backup system is controlled from the admin panel (**Backups**) and from the command line:

| Command | Purpose |
| --- | --- |
| `php scripts/backup-check.php` | Checks that backups are set up correctly on this computer |
| `php scripts/backup.php` | Creates a backup (this is what the scheduled task runs) |
| `php scripts/restore.php --list` | Lists backups that can be restored |
| `php scripts/restore-test.php` | Restores the newest backup into a throw-away test database |
| `php scripts/restore-drill.php` | Full rehearsal on the test database only |
| `scripts/windows/install-scheduler.ps1` | Schedules daily backups in Windows Task Scheduler |

Backups are encrypted with `APP_ENC_KEY`, so keep that key safe. Restoring from the website can be switched off with `RESTORE_WEB_ENABLED=false`.

## Development Notes

* Database access uses PDO. Always use prepared statements when a query contains user input.
* Escape every value printed to a page with `fiE()` (a wrapper around `htmlspecialchars`).
* Every form that changes data must send and verify a CSRF token.
* Pages for signed-in users start with `requireRole('student')` or `requireRole('admin')`.
* Keep existing table and column names unless the whole team agrees on a change.
* Put every schema change in a new numbered file in `database/migrations/`.
* Do not commit `.env`, backups, or uploaded files.

## Team Collaboration

1. Pull the latest changes before starting work.
2. Work on one feature or fix per branch when possible.
3. Test your change in the browser, as a student, as an administrator, and signed out.
4. Commit with clear messages that start with a type: `feat:`, `fix:`, `style:`, `refactor:`, `docs:`, or `chore:`.
5. Pull again, then push.
6. Tell the team before changing the database structure, because everyone has to run the new migration.

Suggested branch names:

```text
feature/login-limits
style/new-logo
fix/database-query
docs/readme-update
```

---

*FindIT — Helping students reconnect with their lost belongings.*