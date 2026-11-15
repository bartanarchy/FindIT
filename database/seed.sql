-- Insert default categories for FindIT.
INSERT INTO public.category (name_category)
SELECT categories.name_category
FROM (
    VALUES
        ('Electronics'),
        ('Accessories'),
        ('Documents'),
        ('Clothing'),
        ('Other')
) AS categories(name_category)
WHERE NOT EXISTS (
    SELECT 1
    FROM public.category existing
    WHERE existing.name_category = categories.name_category
);


-- Create the initial FindIT admin account.
INSERT INTO public.admin (
    email_admin,
    nama_admin,
    password_admin,
    level_access
)
SELECT
    'findit@gmail.com',
    'FindIT Administrator',
    '$2y$10$tX5AMi5J3T9oCqjXJrLDv.kbYbKiDwZf53C2p6gmfxIlFXQzSEbmG',
    'admin'
WHERE NOT EXISTS (
    SELECT 1
    FROM public.admin
    WHERE LOWER(email_admin) = LOWER('findit@gmail.com')
);

-- add this query for change status indonesian became english 

BEGIN;

UPDATE public.item
SET status_barang = CASE status_barang
    WHEN 'Ditemukan' THEN 'Found'
    WHEN 'Diklaim'   THEN 'Claimed'
    ELSE status_barang
END
WHERE status_barang IN ('Ditemukan', 'Diklaim');

UPDATE public.claim
SET status_klaim = CASE status_klaim
    WHEN 'Menunggu Verifikasi' THEN 'Pending Verification'
    WHEN 'Disetujui'           THEN 'Approved'
    WHEN 'Ditolak'             THEN 'Rejected'
    ELSE status_klaim
END
WHERE status_klaim IN ('Menunggu Verifikasi', 'Disetujui', 'Ditolak');

COMMIT;


-- ======================================================================
-- [NEW] NOTIFICATION UPGRADE (same as migration 006)
-- Adds types, severity, safe links, read time and de-duplication to public.notification.
-- Safe to run more than once, and safe on a database that already has these columns
-- (for example one created from the new schema.sql). No existing row is changed.
-- ======================================================================

BEGIN;

ALTER TABLE public.notification
    -- [NEW] What happened: claim_approved, claim_rejected, claim_review, item_claimed, general.
    ADD COLUMN IF NOT EXISTS type       varchar(40)  NOT NULL DEFAULT 'general',
    -- [NEW] How important it is: info, success, warning or danger (controls the colour).
    ADD COLUMN IF NOT EXISTS severity   varchar(10)  NOT NULL DEFAULT 'info',
    -- [NEW] The found item this notification is about.
    ADD COLUMN IF NOT EXISTS id_item    integer,
    -- [NEW] Internal page opened when the notification is clicked.
    ADD COLUMN IF NOT EXISTS link       varchar(255),
    -- [NEW] When the student read it.
    ADD COLUMN IF NOT EXISTS read_at    timestamp without time zone,
    -- [NEW] One key per real event (for example claim:12:approved) to stop duplicates.
    ADD COLUMN IF NOT EXISTS dedupe_key varchar(120);

DO $$
BEGIN
    -- [NEW] Only the four known severities are allowed.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'notification_severity_check') THEN
        ALTER TABLE public.notification
            ADD CONSTRAINT notification_severity_check
            CHECK (severity IN ('info', 'success', 'warning', 'danger'));
    END IF;

    -- [NEW] id_item must point to a real item; if the item is deleted, only the link is cleared.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'notification_id_item_fkey') THEN
        ALTER TABLE public.notification
            ADD CONSTRAINT notification_id_item_fkey
            FOREIGN KEY (id_item) REFERENCES public.item (kode_laporan)
            ON UPDATE CASCADE ON DELETE SET NULL;
    END IF;
END
$$;

-- [NEW] Notifications that were already read get a sensible read time.
UPDATE public.notification
SET read_at = created_at
WHERE is_read = TRUE AND read_at IS NULL;

-- [NEW] Anti-duplicate guard: the same event never notifies the same student twice.
CREATE UNIQUE INDEX IF NOT EXISTS notification_dedupe_key
    ON public.notification (id_user, dedupe_key)
    WHERE dedupe_key IS NOT NULL;

-- [NEW] Speeds up the Notifications page (newest first, with paging).
CREATE INDEX IF NOT EXISTS idx_notification_user_created
    ON public.notification (id_user, created_at DESC, id_notification DESC);

-- [NEW] Speeds up the unread counter on the bell badge.
CREATE INDEX IF NOT EXISTS idx_notification_user_unread_only
    ON public.notification (id_user)
    WHERE is_read = FALSE;

COMMIT;