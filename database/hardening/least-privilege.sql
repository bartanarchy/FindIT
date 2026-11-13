-- OPTIONAL but recommended: run the web app with a restricted database account.
--
-- The app only ever reads, inserts and updates. If it connects as "postgres"
-- (superuser), any bug or SQL injection could DROP tables or DELETE everything.
-- With this account that is impossible: no DELETE, no TRUNCATE, no DROP.
--
-- 1. Open pgAdmin (or psql) as postgres, connected to the FindIT database.
-- 2. Replace CHANGE_ME_STRONG_PASSWORD below, then run this file.
-- 3. In .env set DB_USER=findit_app and DB_PASS=<that password>.
--    Keep the postgres account for maintenance and restore only.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_app') THEN
        CREATE ROLE findit_app LOGIN PASSWORD 'CHANGE_ME_STRONG_PASSWORD'
            NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END
$$;

REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO findit_app;

GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO findit_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO findit_app;

-- Tables created later get the same limited rights.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE ON TABLES TO findit_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO findit_app;


-- ======================================================================
-- Accounts for the backup system (see docs/BACKUP_GUIDE.md)
-- ======================================================================
-- findit_backup : may only READ data, so a leaked backup password cannot change anything.
-- findit_restore: may create databases and rename/replace the FindIT database, used only by
--                 php scripts/restore.php (and the optional Restore button).
-- Replace the passwords, then put them in .env as BACKUP_DB_USER/PASS and RESTORE_DB_USER/PASS.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_backup') THEN
        CREATE ROLE findit_backup LOGIN PASSWORD 'CHANGE_ME_BACKUP_PASSWORD'
            NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_restore') THEN
        CREATE ROLE findit_restore LOGIN PASSWORD 'CHANGE_ME_RESTORE_PASSWORD'
            NOSUPERUSER CREATEDB NOCREATEROLE;
    END IF;
END
$$;

-- PostgreSQL 14+: read every table without owning it (pg_dump works with this).
GRANT pg_read_all_data TO findit_backup;
-- Needed to end other sessions on the database while it is replaced.
GRANT pg_signal_backend TO findit_restore;
-- Lets the restore job check the current database and save a safety copy before replacing it.
GRANT pg_read_all_data TO findit_restore;

-- findit_restore must own the FindIT database to rename it. Run this in the maintenance
-- database (postgres), replacing FindIT with your DB_NAME if different:
--   ALTER DATABASE "FindIT" OWNER TO findit_restore;
-- After a restore, the restore job grants the website account (DB_USER) access to the restored
-- tables automatically, so the site keeps working with findit_app.
