-- Student password reset tokens (hashed, expiring, single-use).
-- Run once against the FindIT database.

BEGIN;

CREATE TABLE IF NOT EXISTS public.password_resets (
    id_reset   BIGSERIAL PRIMARY KEY,
    id_user    INTEGER NOT NULL REFERENCES public."User"(id_user) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_password_resets_user
    ON public.password_resets (id_user);

CREATE INDEX IF NOT EXISTS idx_password_resets_expires
    ON public.password_resets (expires_at);

COMMIT;