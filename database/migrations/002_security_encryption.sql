-- Security upgrade: encrypted columns + blind indexes for lookups.
-- Safe to run more than once. Run it through: php scripts/migrate-security.php
-- (that script also encrypts the rows that already exist).

BEGIN;

-- Ciphertext is longer than the original text, so the limited-length columns become TEXT.
ALTER TABLE public."User"
    ALTER COLUMN email_kampus TYPE text,
    ALTER COLUMN name_user    TYPE text,
    ALTER COLUMN no_telp      TYPE text,
    ALTER COLUMN id_nim       TYPE text;

-- Blind indexes: a keyed hash (HMAC) of the email / NIM. It lets the app find an
-- account and enforce "one account per email / NIM" without decrypting anything.
ALTER TABLE public."User"
    ADD COLUMN IF NOT EXISTS email_bidx varchar(64),
    ADD COLUMN IF NOT EXISTS nim_bidx   varchar(64);

-- Encrypted values are random, so the old UNIQUE rules on the text columns no longer
-- mean anything. Uniqueness is enforced on the blind indexes instead.
ALTER TABLE public."User" DROP CONSTRAINT IF EXISTS "User_email_kampus_key";
ALTER TABLE public."User" DROP CONSTRAINT IF EXISTS "User_id_nim_key";

CREATE UNIQUE INDEX IF NOT EXISTS user_email_bidx_key ON public."User" (email_bidx);
CREATE UNIQUE INDEX IF NOT EXISTS user_nim_bidx_key   ON public."User" (nim_bidx);

ALTER TABLE public.item
    ALTER COLUMN nama_barang TYPE text;

ALTER TABLE public.claim
    ALTER COLUMN kode_pengambilan   TYPE text,
    ALTER COLUMN lokasi_pengambilan TYPE text;

ALTER TABLE public.notification
    ALTER COLUMN title TYPE text;

COMMIT;
