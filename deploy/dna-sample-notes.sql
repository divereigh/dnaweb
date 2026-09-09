-- dna_sample_notes — one note per DNA sample, owned by this app.
--
-- Run once per environment:  mariadb dnaweb < deploy/dna-sample-notes.sql
--
-- WHY
--
-- dna_notes is keyed (sample, mgmtsample) because that is how Ancestry
-- stores tag 3: a note belongs to the *eye* that wrote it, so the same
-- person can carry a different note through every kit you look from.
-- 21,354 samples have notes; 1,643 of them from more than one eye, up
-- to 10. The UI had to pick a "notes eye" before it could show or edit
-- anything, which is why notes were invisible with no eye selected.
--
-- We want one note per sample. This creates the new table and folds
-- dna_notes into it. dna_notes itself is left exactly as it is — once
-- load-dna.pl stops filling it (saveTags(), tag 3), it is frozen
-- history and the rollback path for this migration.
--
-- ORDER OF OPERATIONS
--
--   1. stop load-dna.pl writing dna_notes tag 3
--   2. run this file
--   3. deploy the app (it reads/writes dna_sample_notes only)
--
-- Running it before step 1 is safe but any note Ancestry hands over
-- afterwards lands in dna_notes and is not picked up: both INSERTs are
-- INSERT IGNORE, so re-running only fills samples that have no row yet
-- and never overwrites a note edited in the app.
--
-- MERGE RULE (for the 1,643 multi-eye samples)
--
--   * whitespace-normalised comparison; a note whose text is wholly
--     contained in a longer note for the same sample is dropped as a
--     duplicate (this alone reduces 264 of them to a single note)
--   * what survives is concatenated oldest first, each block headed by
--     the eye that wrote it in [square brackets], blank line between.
--     Notes are multi-line (16,774 of them, up to 39 lines), so the
--     attribution is a header line rather than an inline prefix
--   * a sample left with one note is stored bare, with no header
--
-- Longest result is 3,354 chars, well past dna_notes.notes' varchar
-- (1000) — hence TEXT.

CREATE TABLE IF NOT EXISTS dna_sample_notes (
  `sample`     int(11) unsigned NOT NULL,
  `notes`      text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sample`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP_CONCAT silently truncates at group_concat_max_len. The server
-- default is 1024, which would cut 80 of the merged notes mid-word.
SET SESSION group_concat_max_len = 1000000;

-- 1. Samples with exactly one note: copy it across, trimmed.
INSERT IGNORE INTO dna_sample_notes (sample, notes)
SELECT dn.sample,
       REGEXP_REPLACE(REGEXP_REPLACE(dn.notes, '^[[:space:]]+', ''), '[[:space:]]+$', '')
  FROM dna_notes dn
  JOIN (SELECT sample FROM dna_notes GROUP BY sample HAVING COUNT(*) = 1) one
    ON one.sample = dn.sample
 WHERE TRIM(REGEXP_REPLACE(dn.notes, '[[:space:]]+', ' ')) <> '';

-- 2. Samples with notes from several eyes: dedupe, then merge.
INSERT IGNORE INTO dna_sample_notes (sample, notes)
WITH multi AS (
  SELECT sample FROM dna_notes GROUP BY sample HAVING COUNT(*) > 1
),
n AS (
  SELECT dn.sample, dn.mgmtsample, dn.loaded,
         -- stored as written, minus leading/trailing whitespace
         REGEXP_REPLACE(REGEXP_REPLACE(dn.notes, '^[[:space:]]+', ''), '[[:space:]]+$', '') AS body,
         -- compared with newlines and runs of spaces flattened, so
         -- "A / B" and "A\nB" count as the same text
         LOWER(TRIM(REGEXP_REPLACE(dn.notes, '[[:space:]]+', ' '))) AS norm
    FROM dna_notes dn
    JOIN multi m ON m.sample = dn.sample
),
kept AS (
  SELECT n.* FROM n
   WHERE n.norm <> ''
     -- Drop this note if another note on the same sample already
     -- contains it. LOCATE, not LIKE: note text contains % (e.g.
     -- "1% A G [Johnson/Hambrook]") which LIKE would read as a
     -- wildcard. The length/mgmtsample tiebreak makes the rule a
     -- strict order, so two notes that normalise identically cannot
     -- drop each other and leave the sample with nothing.
     AND NOT EXISTS (
           SELECT 1 FROM n o
            WHERE o.sample = n.sample
              AND o.mgmtsample <> n.mgmtsample
              AND LOCATE(n.norm, o.norm) > 0
              AND (CHAR_LENGTH(o.norm) > CHAR_LENGTH(n.norm)
                OR (CHAR_LENGTH(o.norm) = CHAR_LENGTH(n.norm)
                    AND o.mgmtsample < n.mgmtsample))
         )
),
labelled AS (
  SELECT k.sample, k.mgmtsample, k.loaded, k.body,
         -- same precedence as the app's display_label: person name
         -- first, then the kit's Ancestry display name
         COALESCE(NULLIF(TRIM(pe.fullName), ''), NULLIF(TRIM(e.displayName), ''),
                  CONCAT('sample #', k.mgmtsample)) AS eye_label,
         COUNT(*) OVER (PARTITION BY k.sample) AS kept_n
    FROM kept k
    LEFT JOIN dna_samples e  ON e.id = k.mgmtsample
    LEFT JOIN people      pe ON pe.dnaSampleId = k.mgmtsample
)
SELECT sample,
       GROUP_CONCAT(
         CASE WHEN kept_n > 1 THEN CONCAT('[', eye_label, ']', CHAR(10), body) ELSE body END
         ORDER BY loaded, mgmtsample
         SEPARATOR '\n\n')
  FROM labelled
 GROUP BY sample;

-- Expected on a first run against the database as it stood 2026-09-09:
--   21353 rows — 19,711 samples with a single eye's note, 1,643 with
--   notes from several (264 of those collapsing to one note, 1,379
--   keeping attributed blocks), longest result 3,354 chars.
--
--   `missed` should be 1: one sample's only note is a bare newline,
--   which normalises to empty and is correctly not carried over.
--
--   `multi_eye_merged` counts notes starting with "[", so it reads
--   1,380 rather than 1,379 — one collapsed single note happens to
--   begin with a bracketed surname group. It is an indicator, not an
--   assertion.
SELECT COUNT(*) AS sample_notes_rows FROM dna_sample_notes;
SELECT COUNT(*) AS multi_eye_merged FROM dna_sample_notes WHERE notes LIKE '[%';
SELECT COUNT(*) AS missed
  FROM (SELECT DISTINCT sample FROM dna_notes) d
  LEFT JOIN dna_sample_notes sn ON sn.sample = d.sample
 WHERE sn.sample IS NULL;
