-- Canonical schema for dna_matches2 — the directional (two-rows-per-pair)
-- replacement for dna_matches. Run once per environment before the seed.
--
-- Schema-owner conceptually is the Perl loader project at ~/ancestry/program;
-- this file is checked into the web app repo so any environment that runs
-- the deploy script can lay the table down without separate coordination.

CREATE TABLE IF NOT EXISTS dna_matches2 (
  sample1                int(11) unsigned NOT NULL COMMENT 'viewer — perspective for matchClusterCode + predictedKinships',
  sample2                int(11) unsigned NOT NULL COMMENT 'viewed',
  sharedCentimorgans     smallint(5) NOT NULL,
  numSharedSegments      smallint(5) NOT NULL,
  meiosis                smallint(5) NOT NULL,
  matchClusterCode       char(10)         DEFAULT NULL,
  predictedKinships      varchar(200)     DEFAULT NULL,
  ignored                tinyint(1)       NOT NULL DEFAULT 0,
  dnapath                char(5)          DEFAULT NULL,
  auto                   tinyint(1)       NOT NULL DEFAULT 0 COMMENT '1 = seeded, never refreshed by a real loader fetch',
  loaded                 datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (sample1, sample2),
  KEY idx_sample1_cm      (sample1, sharedCentimorgans),
  KEY idx_sample1_cluster (sample1, matchClusterCode),
  KEY idx_sample2_cm      (sample2, sharedCentimorgans),  -- needed for "matches involving X as the viewed party"
  KEY idx_auto            (auto)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- One-shot seed from the existing dna_matches table:
--
-- INSERT INTO dna_matches2 (sample1, sample2, sharedCentimorgans, numSharedSegments,
--                          meiosis, matchClusterCode, predictedKinships, ignored, dnapath, auto)
-- SELECT sample1, sample2, sharedCentimorgans, numSharedSegments, meiosis,
--        NULL, NULL, ignored, dnapath, 1
-- FROM dna_matches
-- UNION ALL
-- SELECT sample2, sample1, sharedCentimorgans, numSharedSegments, meiosis,
--        NULL, NULL, ignored, dnapath, 1
-- FROM dna_matches;
