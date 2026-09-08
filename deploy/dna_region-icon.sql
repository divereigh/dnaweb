-- Per-region icon for the name column on /dna/{id}/matches.
--
-- A region with a non-NULL icon paints that image next to the name of
-- every sample holding more than 0% of it. Ancestry has no such concept:
-- this is our own annotation, for the handful of regions where "has any
-- of this at all" is worth spotting at a glance (Aboriginal Australian,
-- Ashkenazi, Romani) rather than the percentage being the interesting
-- part. It is deliberately per-region and not per-macro-region, so
-- "Ireland" can stay unmarked while a single region under it is marked.
--
-- The column is app-owned, in the same arrangement as tree.colour and the
-- *_phonetic columns: the Perl loaders name their columns explicitly on
-- INSERT ... ON DUPLICATE KEY UPDATE, so a nullable column they don't
-- know about survives a region refresh untouched.
--
-- Values are a bare filename, resolved against public/region-icons/ by
-- OriginsService. No path, no leading slash: 'Star_of_David.svg'.
--
-- Run once:
--   mariadb dnaweb < deploy/dna_region-icon.sql

ALTER TABLE dna_region
    ADD COLUMN IF NOT EXISTS icon varchar(64) DEFAULT NULL
    COMMENT 'Filename under public/region-icons/, e.g. Star_of_David.svg. NULL = no icon.';

-- Assign icons by hand. Find the region first, e.g.
--
--   SELECT regionKey, version, regionName, macroRegionName
--     FROM dna_region
--    WHERE regionName LIKE '%Jewish%';
--
-- then set it:
--
--   UPDATE dna_region SET icon = 'Star_of_David.svg'            WHERE regionKey = '...';
--   UPDATE dna_region SET icon = 'Australian_Aboriginal_Flag.svg' WHERE regionKey = '...';
--   UPDATE dna_region SET icon = 'Flag_of_the_Romani_people.svg'  WHERE regionKey = '...';
--
-- Several regions may share one icon — the matches page shows it once per
-- sample either way, and names every contributing region in its tooltip.
