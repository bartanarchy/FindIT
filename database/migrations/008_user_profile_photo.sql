-- Student profile picture: either an uploaded photo or one of the built-in avatars.
-- Safe to run more than once. Existing data is not touched.
--
-- One row per student who chose a picture (no row = default icon).
--   avatar_preset : key of a built-in avatar (e.g. 'a1'), NULL when a photo is used.
--   mime/foto_data: the uploaded photo, encrypted by PHP (AES-256-GCM, same APP_ENC_KEY as the
--                   rest of the data), NULL when a built-in avatar is used.
CREATE TABLE IF NOT EXISTS public.user_photo (
    id_user       integer PRIMARY KEY
                      REFERENCES public."User"(id_user) ON DELETE CASCADE,
    avatar_preset character varying(10),
    mime          character varying(20)
                      CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp')),
    foto_data     text,
    updated_at    timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_photo_one_source CHECK (
        (avatar_preset IS NOT NULL AND foto_data IS NULL AND mime IS NULL)
        OR (avatar_preset IS NULL AND foto_data IS NOT NULL AND mime IS NOT NULL)
    )
);

-- Give the restricted application account (see hardening/least-privilege.sql) access.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'findit_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON public.user_photo TO findit_app;
    END IF;
END
$$;
