-- FULLTEXT name search: lexical + phonetic.
--
-- Adds:
--   * dna_samples.displayName_phonetic — space-joined Metaphone codes
--   * people.fullName_phonetic         — same
--   * four FULLTEXT indexes (raw + phonetic on each table)
--
-- After running this, populate the new columns once via
--   php artisan dna:backfill-phonetic
--
-- Going forward the Laravel observers (DnaSampleObserver / PersonObserver)
-- and the Perl loaders (treelib::phonetic_encode) keep both columns in
-- sync on write. The nightly `dna:backfill-phonetic --where-null` sweep
-- is the safety net for anything that slipped through.
--
-- my.cnf prerequisites (one-time, requires MariaDB restart):
--   [mysqld]
--   innodb_ft_min_token_size = 2     # default 3; lower so 2-letter
--                                    # surnames (Li, Yi, Bo) get indexed
--   innodb_ft_enable_stopword = OFF  # default English stopwords aren't
--                                    # relevant for names ("be" etc.)
--
-- After the cnf change + restart, you must DROP + ADD any existing
-- InnoDB FULLTEXT index for the new token size to take effect — these
-- indexes are new, so a fresh ADD picks up the new size automatically.

ALTER TABLE dna_samples
    ADD COLUMN displayName_phonetic VARCHAR(255) NULL AFTER displayName;

ALTER TABLE people
    ADD COLUMN fullName_phonetic VARCHAR(500) NULL AFTER fullName;

ALTER TABLE dna_samples
    ADD FULLTEXT INDEX ft_displayName (displayName),
    ADD FULLTEXT INDEX ft_displayName_phonetic (displayName_phonetic);

ALTER TABLE people
    ADD FULLTEXT INDEX ft_fullName (fullName),
    ADD FULLTEXT INDEX ft_fullName_phonetic (fullName_phonetic);
