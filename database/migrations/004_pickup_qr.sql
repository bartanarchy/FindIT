-- QR Code-based pickup verification.
-- Safe to run more than once. Existing claim / item data is not touched.
--
-- One row per approved claim (UNIQUE id_claim = one active pickup token per claim).
--   token_hash : SHA-256 of the secret token. The scanner looks the token up by this hash.
--   token_enc  : the same token encrypted by PHP (AES-256-GCM), only so the student can
--                see / download their own QR code again later.
--   used_at / id_admin_konfirmasi : no longer used (kept so the table stays compatible).
CREATE TABLE IF NOT EXISTS public.pickup_token (
    id_pickup           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_claim            integer NOT NULL UNIQUE
                            REFERENCES public.claim(id_claim) ON DELETE CASCADE,
    token_hash          char(64) NOT NULL UNIQUE,
    token_enc           text NOT NULL,
    created_at          timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at             timestamp without time zone,
    id_admin_konfirmasi integer REFERENCES public.admin(id_admin),
    CONSTRAINT pickup_token_used_pair
        CHECK ((used_at IS NULL) = (id_admin_konfirmasi IS NULL))
);

-- Give the restricted application account (see hardening/least-privilege.sql) access.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_app') THEN
        GRANT SELECT, INSERT, UPDATE ON public.pickup_token TO findit_app;
        GRANT USAGE, SELECT ON SEQUENCE public.pickup_token_id_pickup_seq TO findit_app;
    END IF;
END
$$;
