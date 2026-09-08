-- Local override for the p1/p2 -> PATERNAL/MATERNAL mapping.
--
-- dna_samples.paternalCluster names which of a kit's two parent clusters
-- ('p1' or 'p2') is the paternal one. It comes from Ancestry: load-dna.pl
-- fetches discoveryui-matches/cluster/api/paternalCluster/<uuid> and does
--
--     if (!$fail && $paternalCluster) {
--         UPDATE dna_samples SET paternalCluster=? WHERE dnaUUID=?
--
-- so it is only ever written when Ancestry actually returns a value. A kit
-- whose owner has never labelled their sides in Ancestry returns nothing,
-- the column stays empty forever, and every ParentSide pill for that eye
-- falls back to showing the raw cluster code -- 'P1' / 'P2' instead of
-- PATERNAL / MATERNAL. 24 of our eyes are in that state, some of them large
-- (Lynne Brown alone has ~88k clustered matches).
--
-- This column is our own answer to the same question, and it WINS over
-- Ancestry's -- it covers both "the owner never said" and "the owner said,
-- and they are wrong". Readers resolve it with
--
--     COALESCE(NULLIF(s.paternalClusterOverride,''), NULLIF(s.paternalCluster,''))
--
-- (App\Support\Sql::effectivePaternalCluster), so a single value flows into
-- both consumers of the mapping -- ClusterPill.vue and
-- DnaSampleService::parentSideFilter() -- and display and the ParentSide
-- filter dropdown stay in agreement without either of them knowing an
-- override exists.
--
-- Nothing about Ancestry's own data is touched: paternalCluster keeps
-- whatever the loader put there and is still shown alongside on /eyes.
--
-- App-owned, in the same arrangement as tree.colour, dna_region.icon and
-- the *_phonetic columns: every loader write to dna_samples is a
-- named-column UPDATE or INSERT (there is no REPLACE INTO anywhere in
-- ancestry-program), so a nullable column they do not know about survives
-- a reload untouched.
--
-- Run once:
--   mariadb dnaweb < deploy/paternal-cluster-override.sql

ALTER TABLE dna_samples
    ADD COLUMN IF NOT EXISTS paternalClusterOverride char(5) DEFAULT NULL
    COMMENT 'App-owned override of paternalCluster: p1|p2, NULL = defer to Ancestry.'
    AFTER paternalCluster;
