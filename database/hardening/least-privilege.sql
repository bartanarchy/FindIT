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
