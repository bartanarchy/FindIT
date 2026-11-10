# FindIT — Lost and Found Platform

FindIT is a web-based Lost and Found platform designed to help students in the Information Technology department report found items and recover their lost belongings.

The platform aims to make it easier for students to find information about lost items and provide a structured process for claiming belongings.

## Project Status

**Current Stage:** Initial Development — Homepage and Database Integration

### Completed

* [x] Created the PostgreSQL database `FindIT`.
* [x] Created the database tables and relationships.
* [x] Configured the PHP database connection using PDO.
* [x] Connected the application to PostgreSQL successfully.
* [x] Retrieved item data from the database using SQL.
* [x] Displayed found items on the homepage.
* [x] Displayed item names, categories, locations, and discovery dates.
* [x] Added a View Details button to each item card.
* [x] Added initial test data for development.

### In Progress / Planned

* [ ] Develop the Item Detail page.
* [ ] Implement user registration and login.
* [ ] Implement secure password hashing and session management.
* [ ] Develop the item claim submission process.
* [ ] Display claim status and claim details.
* [ ] Develop the admin dashboard.
* [ ] Implement item and claim management for administrators.
* [ ] Improve the user interface and responsive design.
* [ ] Perform application testing and security checks.

## Tech Stack

| Technology         | Purpose                                     |
| ------------------ | ------------------------------------------- |
| PHP 8+             | Backend development                         |
| PostgreSQL 18      | Relational database                         |
| PDO                | Database connection and prepared statements |
| Bootstrap 5        | Responsive user interface                   |
| HTML5              | Page structure                              |
| CSS3               | Custom styling                              |
| Vanilla JavaScript | Client-side interactions                    |
| Laragon            | Local development environment               |
| Git and GitHub     | Version control and team collaboration      |

## Project Structure

```text
FindIT/
├── admin/
│   ├── claim-detail.php
│   ├── claims.php
│   ├── index.php
│   └── items.php
├── app/
│   ├── controllers/
│   │   ├── AdminController.php
│   │   ├── AuthController.php
│   │   ├── ClaimController.php
│   │   └── ItemController.php
│   ├── helpers/
│   │   ├── auth.php
│   │   ├── backup.php
│   │   ├── campus_email.php
│   │   ├── crypto.php
│   │   └── csrf.php
│   └── models/
│       ├── Claim.php
│       ├── Item.php
│       └── User.php
├── config/
│   └── config.php
├── database/
│   ├── schema.sql
│   ├── migrations/
│   └── hardening/
├── scripts/            (key, migration, backup, restore: command line only)
├── public/
│   ├── assets/
│   │   ├── css/
│   │   │   └── style.css
│   │   ├── images/
│   │   └── js/
│   │       └── app.js
│   ├── uploads/
│   ├── index.php
│   ├── login.php
│   ├── register.php
│   ├── item-detail.php
│   └── claim.php
├── storage/
├── .env
├── .gitignore
└── README.md
```

*Note: Some files and directories have been created as part of the project structure, but their features may not be fully implemented yet.*

## Database Structure

The application uses PostgreSQL with the following tables:

| Table      | Purpose                                                 |
| ---------- | ------------------------------------------------------- |
| `"User"`   | Stores student account information                      |
| `admin`    | Stores administrator accounts and access levels         |
| `category` | Stores item categories                                  |
| `item`     | Stores reported found-item information                  |
| `location` | Stores item discovery locations                         |
| `claim`    | Stores item claim requests and verification information |

The database relationships connect users, categories, locations, items, administrators, and claims.

The database schema is documented in `database/schema.sql`.

**Important:** The database has already been created in the local development environment. Do not execute the schema file again against the existing database without checking whether the tables already exist.

## Security: campus-only accounts, encryption and backup

### Campus-only accounts

Registration and student login only accept `NIM@student.polinema.ac.id` (digits before the `@`).
The rule is enforced on the server (`app/helpers/campus_email.php`), not just in the form.
Administrator accounts are created in the `admin` table, not through registration.

> The rule checks the email *format*. It does not prove the person owns that mailbox.
> Email verification (a confirmation link or code) is the next step if that is needed.

### Encrypted database

Sensitive columns are encrypted by PHP (AES-256-GCM) before they are saved, so anyone reading the
database directly (pgAdmin, a stolen dump, SQL injection) only sees `enc:v1:...` text.

| Table | Encrypted columns |
| --- | --- |
| `"User"` | `email_kampus`, `name_user`, `no_telp`, `id_nim` (password is a bcrypt hash) |
| `item` | `nama_barang`, `detail_lokasi`, `ciri_umum`, `ciri_khusus` |
| `claim` | `lokasi_kehilangan`, `terakhir_di_lihat`, `ciri_khusus_claim`, `catatan_admin`, `kode_pengambilan`, `lokasi_pengambilan` |
| `notification` | `title`, `message` |

Email and NIM are looked up through a *blind index* (`email_bidx`, `nim_bidx`: a keyed hash), so login
and "already registered" checks work without decrypting anything.

The key is `APP_ENC_KEY` in `.env`. **Keep an offline copy (password manager / USB). If it is lost,
the encrypted data and the backups cannot be recovered.** Never commit `.env`.

### Encrypted backup (outside the database)

* **Journal** (`storage/backup/journal.log`): every write (registration, item report, claim, admin
  decision, notification) is also saved here, encrypted, append-only and hash-chained.
* **Snapshots** (`storage/backup/snapshot-*.fbk`): a full encrypted copy of every table.
* A restore = newest snapshot + the journal entries after it, so it works even if the database was dropped.
* Set `BACKUP_PATH` in `.env` to keep the backup on another drive or a synced folder.

### First-time setup (existing database)

```bash
php scripts/generate-key.php --write     # creates APP_ENC_KEY in .env, then copy it somewhere safe
php scripts/migrate-security.php         # updates tables, encrypts existing rows, first snapshot
php scripts/verify-backup.php            # confirms the backup opens and the journal is intact
```

Take a normal database backup in pgAdmin before running `migrate-security.php`.
Existing accounts that do not use `NIM@student.polinema.ac.id` are listed by the script and can no longer sign in.

### Everyday commands

```bash
php scripts/backup-snapshot.php          # full snapshot; schedule daily (Windows Task Scheduler / cron)
php scripts/verify-backup.php            # check backup health
php scripts/restore.php --dry-run        # preview a restore
php scripts/restore.php                  # restore after data loss (create an empty FindIT database first)
```

Copy the backup folder to a second place regularly. A backup on the same disk as the database does not
protect against losing the whole machine.

### Optional: restricted database account

`database/hardening/least-privilege.sql` creates `findit_app`, which can read, insert and update but not
delete, truncate or drop. Run the app with it (`DB_USER=findit_app`) and keep `postgres` for maintenance and restore.

## Getting Started

### Requirements

* Windows with Laragon installed
* PHP 8.0 or later
* PostgreSQL 18 or a compatible PostgreSQL version
* PHP PostgreSQL extensions: `pdo_pgsql` and `pgsql`
* A web browser
* Git, if working with the GitHub repository

### 1. Clone the Repository

Open a terminal in your Laragon `www` directory and run:

```bash
git clone <repository-url>
```

Replace `<repository-url>` with the actual GitHub repository URL.

Navigate into the project directory:

```bash
cd FindIT
```

The expected project root is the directory containing `config/`, `database/`, `public/`, and `app/`.

### 2. Configure Environment Variables

Create a local `.env` file in the project root if one does not already exist.

Example configuration:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=FindIT
DB_USER=postgres
DB_PASS=your_local_database_password
APP_ENC_KEY=            # generate with: php scripts/generate-key.php --write
BACKUP_PATH=            # optional: folder for encrypted backups
```

Replace the example password with your local PostgreSQL password.

Do not commit `.env` or share database credentials through GitHub.

### 3. Configure PostgreSQL

1. Start the required services in Laragon.
2. Make sure PostgreSQL is running.
3. Open pgAdmin.
4. Create or select the `FindIT` database.
5. Verify that the required tables exist.

If the database is already initialized, do not recreate its tables. Use `database/schema.sql` as a schema reference unless you intentionally need to set up a fresh database.

### 4. Run the Application

Make sure the project is located under Laragon's `www` directory.

Start Apache and open:

```text
http://localhost/FindIT/FindIT/public/
```

The URL assumes the current nested folder structure. If the project is moved or renamed, adjust the URL accordingly.

### 5. Verify the Homepage

The homepage should display found items retrieved from PostgreSQL.

Current development test data includes:

* **Item:** Black Wireless Headphones
* **Category:** Electronics
* **Location:** IT Building, 5th Floor
* **Status:** Ditemukan

This is development test data, not a real item report.

## Development Notes

* PHP database access uses PDO.
* SQL queries should use prepared statements when handling user-provided values.
* Escape output with `htmlspecialchars()` to reduce the risk of cross-site scripting (XSS).
* User passwords must be stored using `password_hash()` and verified using `password_verify()`.
* Authentication and authorization must be implemented before protected features are considered complete.
* Database credentials and other secrets must not be committed to GitHub.
* The current homepage displays items whose `status_barang` value is `Ditemukan`.
* Existing database table and column names should be preserved unless the team agrees on a schema change.

## Team Collaboration

To contribute to the project:

1. Pull the latest changes from the shared repository.
2. Work on an assigned feature or branch.
3. Test your changes locally.
4. Commit changes with clear, descriptive messages.
5. Push your branch and open a pull request when appropriate.
6. Coordinate database schema changes with the team before applying them.

Suggested branch names:

```text
feature/item-detail
feature/authentication
feature/claim-system
feature/admin-dashboard
fix/database-query
```

## Next Development Milestone

**Milestone 1: Item Detail Page**

The next task is to implement `public/item-detail.php`. It should retrieve an item using its `kode_laporan` and display its full details when a user clicks View Details on the homepage.

Afterward, development will continue with authentication, claim submission, and administrator functionality.

---

*FindIT — Helping students reconnect with their lost belongings.*
