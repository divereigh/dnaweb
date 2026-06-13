-- Per-direction "assignment" string from Ancestry's relationship API.
--
-- This is the headline relationship label Ancestry returns for a pair
-- (e.g. "1st cousin", or a cM-style "2nd cousin or half 1st cousin 1x
-- removed"). For confirmed/known matches it reflects what the match
-- owner has *stated* the relationship is — useful even when it
-- disagrees with the cM-derived predictedKinships (which we parse from
-- predictedKinshipPaths). Stored verbatim alongside predictedKinships.
--
-- App/loader-owned addition; nullable so existing rows are unaffected
-- until re-fetched.

ALTER TABLE dna_matches2
    ADD COLUMN assignment VARCHAR(255) NULL AFTER predictedKinships;
