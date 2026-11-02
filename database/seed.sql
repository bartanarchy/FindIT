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
