-- One-shot migration: create dna_matches_kinships and backfill from
-- dna_matches2.predictedKinships for rows with cM > 200.
--
-- The CSV column in dna_matches2 stays — it's the archival source
-- and continues to capture predictions for low-cM matches. This
-- normalised table is the queryable / joinable view for the rows
-- worth displaying.
--
-- Field names mirror dna_matches2 so the planned rename of
-- dna_matches2 -> dna_matches doesn't force us to rename here too.
--
-- Run once:
--   mariadb dnaweb < deploy/dna_matches_kinships.sql

CREATE TABLE IF NOT EXISTS dna_matches_kinships (
    sample1 int(11) unsigned NOT NULL,
    sample2 int(11) unsigned NOT NULL,
    ordinal tinyint(3) unsigned NOT NULL COMMENT '1 = primary prediction',
    nspath  char(20) NOT NULL,
    PRIMARY KEY (sample1, sample2, ordinal),
    KEY by_nspath (nspath),
    KEY by_pair (sample1, sample2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill from existing CSV.  predictedKinships is always at most 3
-- comma-separated tokens, so we cross-join with an inline 1..3 series
-- and SUBSTRING_INDEX to slice out token N.
--
-- ON DUPLICATE KEY UPDATE makes this safe to re-run if the schema is
-- already partly populated.
INSERT INTO dna_matches_kinships (sample1, sample2, ordinal, nspath)
SELECT m.sample1, m.sample2, n.n,
       TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(m.predictedKinships, ',', n.n), ',', -1))
FROM dna_matches2 m
JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3) n
  ON CHAR_LENGTH(m.predictedKinships) - CHAR_LENGTH(REPLACE(m.predictedKinships, ',', '')) >= n.n - 1
WHERE m.sharedCentimorgans > 200
  AND m.predictedKinships IS NOT NULL
  AND m.predictedKinships <> ''
ON DUPLICATE KEY UPDATE nspath = VALUES(nspath);
