-- QR scanning was removed: an approved claim now marks its item as Claimed right away.
-- Items whose claim was approved while the scan flow was active are still "Found";
-- this brings them in line. Safe to run more than once.
UPDATE public.item AS i
SET status_barang = 'Claimed'
WHERE i.status_barang = 'Found'
  AND EXISTS (
      SELECT 1 FROM public.claim AS c
      WHERE c.id_item = i.kode_laporan
        AND c.status_klaim = 'Approved'
  );
