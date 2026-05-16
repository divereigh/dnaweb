-- View: v_pending_match2match
--
-- Pairs of (managed eye, other sample) that have not yet finished
-- loading their match-of-match data, i.e. dna_match2match_loaded
-- either has no row at all or shows pagination is incomplete
-- (totalPages still NULL, or lastPage < totalPages).
--
-- Joined back to dna_samples / session so we only ever surface
-- pairs that can actually be loaded — both samples enabled, the
-- eye is managed, and a session row exists.
--
-- Consumers add their own scope and retry predicates on top —
-- the view does NOT filter on fail/success/loaded timestamps, so
-- everything downstream stays in caller code.

CREATE OR REPLACE
SQL SECURITY INVOKER
VIEW v_pending_match2match AS
SELECT
  dm.sample1            AS mgmtsample,
  dm.sample2            AS othsample,
  dm.sharedCentimorgans AS sharedCentimorgans,
  l.totalPages          AS totalPages,
  l.lastPage            AS lastPage,
  l.loaded              AS loaded,
  l.success             AS success,
  l.fail                AS fail
FROM dna_matches2 dm
INNER JOIN dna_samples mgmt
  ON mgmt.id = dm.sample1
 AND mgmt.disabled = 0
 AND mgmt.managed IS NOT NULL
INNER JOIN dna_samples oth
  ON oth.id = dm.sample2
 AND oth.disabled = 0
INNER JOIN session
  ON mgmt.managed = session.id
LEFT JOIN dna_match2match_loaded l
  ON l.mgmtsample = dm.sample1
 AND l.othsample  = dm.sample2
WHERE l.totalPages IS NULL OR l.lastPage < l.totalPages;
