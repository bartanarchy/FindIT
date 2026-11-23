-- Campus email verification.
-- A new student account starts as "not verified". The student presses "Send verification email"
-- on the profile page, opens the link sent to the campus email, and the account becomes verified.
-- Only verified students can report found items and submit claims.
--
-- Safe to run more than once.
--   "User".email_verified_at : NULL = not verified yet, otherwise the time it was verified.
--   email_verifications       : one-time links (only a SHA-256 hash of the token is stored).

BEGIN;

-- Students who registered BEFORE this feature are treated as already verified, so nobody is
-- locked out. This runs only the first time (when the column is added), so running the file
-- again never verifies accounts that are still waiting. Accounts created later start as
-- not verified. To test with an old account:
--   UPDATE public."User" SET email_verified_at = NULL WHERE id_user = <id>;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'User' AND column_name = 'email_verified_at'
    ) THEN
        ALTER TABLE public."User" ADD COLUMN email_verified_at timestamp without time zone;
        UPDATE public."User" SET email_verified_at = COALESCE(created_at, CURRENT_TIMESTAMP);
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS public.email_verifications (
    id_verification BIGSERIAL PRIMARY KEY,
    id_user         INTEGER NOT NULL REFERENCES public."User"(id_user) ON DELETE CASCADE,
    token_hash      CHAR(64) NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ NOT NULL,
    used_at         TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_verifications_user
    ON public.email_verifications (id_user);

-- Give the restricted application account (see hardening/least-privilege.sql) access.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_app') THEN
        GRANT SELECT, INSERT, UPDATE ON public.email_verifications TO findit_app;
        GRANT USAGE, SELECT ON SEQUENCE public.email_verifications_id_verification_seq TO findit_app;
    END IF;
END
$$;

COMMIT;
