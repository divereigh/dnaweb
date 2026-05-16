-- One-shot migration: turn dna_match2match_loaded into a queue.
--
-- Adds explicit queue state (status, enqueued_at, claimed_at,
-- claimed_by, priority, attempts, next_retry_at) and the two indexes
-- the worker needs for fast claiming.
--
-- The view v_pending_match2match is rebuilt to consult the new status
-- column (and falls back to "pending" for any pair that doesn't yet
-- have a dna_match2match_loaded row).
--
-- Run once on production:
--   mariadb dnaweb < deploy/queue-migration.sql
-- NOT idempotent. Not part of deploy.sh.

ALTER TABLE dna_match2match_loaded
    ADD COLUMN status ENUM('pending','running','done','abandoned')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN enqueued_at   TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN claimed_at    TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN claimed_by    VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN priority      SMALLINT NOT NULL DEFAULT 100,
    ADD COLUMN attempts      SMALLINT NOT NULL DEFAULT 0,
    ADD COLUMN next_retry_at TIMESTAMP NULL DEFAULT NULL,
    ADD KEY queue_pick (status, priority, enqueued_at),
    ADD KEY queue_mgmt (status, mgmtsample);

-- Backfill the new status column from the existing data.
-- success=1                  -> 'done'  (8,867 rows + the ~19k legacy
--                                        success=1 rows where totalPages
--                                        was never recorded — trust the
--                                        flag, don't re-fetch)
-- success=0 OR fail=1        -> 'abandoned' (the 26 dead rows)
-- everything else (partials) -> 'pending'
UPDATE dna_match2match_loaded SET status = CASE
    WHEN success = 1             THEN 'done'
    WHEN success = 0 OR fail = 1 THEN 'abandoned'
    ELSE 'pending'
END;

-- Pretend the abandoned rows already burned their 3 attempts so the
-- worker doesn't immediately re-try them on first boot.
UPDATE dna_match2match_loaded SET attempts = 3 WHERE status = 'abandoned';

-- Replace the view so it now consults the status column. A pair with
-- no loaded row at all is treated as pending (LEFT JOIN COALESCE).
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
    l.fail                AS fail,
    COALESCE(l.status, 'pending') AS status,
    COALESCE(l.priority, 100) AS priority,
    COALESCE(l.attempts, 0)   AS attempts,
    l.next_retry_at       AS next_retry_at
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
WHERE COALESCE(l.status, 'pending') NOT IN ('done', 'abandoned');
