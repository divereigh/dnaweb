-- Per-tree pill colour for the matches-page Trees column.
--
-- NULL = no colour assigned → the UI renders a white pill with a
-- border. A hex string like '#facc15' paints the pill background.
-- The Perl loaders don't touch this column (it's app-owned, same
-- arrangement as the *_phonetic columns on dna_samples / people).

ALTER TABLE tree
    ADD COLUMN colour VARCHAR(7) NULL AFTER description;
