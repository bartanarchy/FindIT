-- One claim per student per item (a rejected student cannot claim the same item again).
-- If this fails with "could not create unique index", duplicate rows exist already:
--   SELECT id_item, id_user_pengaju, COUNT(*) FROM public.claim
--   GROUP BY 1,2 HAVING COUNT(*) > 1;
-- Clean those up first, then run this again.
CREATE UNIQUE INDEX IF NOT EXISTS uq_claim_one_per_user_item
    ON public.claim (id_item, id_user_pengaju);
