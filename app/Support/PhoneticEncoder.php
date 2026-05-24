<?php

namespace App\Support;

class PhoneticEncoder
{
    /**
     * Tokenise a name on whitespace and the punctuation we see in real
     * data — parens (maiden names), brackets (placeholders), hyphens,
     * apostrophes, quotes, commas, dots — Metaphone-encode each token,
     * and join with spaces. The result feeds a FULLTEXT-indexed column;
     * MariaDB's tokeniser then breaks on the spaces we put in.
     *
     * The Perl side (treelib::phonetic_encode) must mirror this exactly
     * so loaders and the Laravel app agree on what's stored.
     */
    public static function encode(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }
        $tokens = preg_split(
            '/[\s()\[\]\-\'",.]+/u',
            mb_strtolower($name),
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        $codes = [];
        foreach ($tokens as $token) {
            // Single-letter tokens won't survive the FT min-token floor
            // anyway, and Metaphone of "a" / "y" produces noise that
            // would just dilute scores.
            if (mb_strlen($token) < 2) {
                continue;
            }
            $code = metaphone($token);
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return implode(' ', $codes);
    }

    /**
     * Turn a user search string into the two BOOLEAN-mode expressions
     * used against the lexical and phonetic FULLTEXT indexes.
     *
     * Lexical side gets a `+token*` per word so prefix-of-surname
     * searches ("wads") match Wadsworth / Wadley. Phonetic side gets
     * a `+METAPHONE_CODE` per word — no prefix because Metaphone codes
     * are short and prefix-matching them would over-match.
     *
     * Returns ['', ''] for empty input so callers can short-circuit.
     *
     * @return array{0:string,1:string}
     */
    public static function buildBoolean(string $query): array
    {
        $tokens = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $lex = [];
        $codes = [];
        foreach ($tokens as $t) {
            // Strip BOOLEAN-mode operators so a user pasting "Mary+Jane"
            // doesn't accidentally trigger required-term semantics.
            $safe = preg_replace('/[+\-<>~()@*"\']/u', ' ', mb_strtolower($t));
            $safe = trim($safe);
            if ($safe === '' || mb_strlen($safe) < 2) continue;
            $lex[] = '+' . $safe . '*';
            $code = metaphone($safe);
            if ($code !== '') {
                $codes[] = '+' . $code;
            }
        }
        return [implode(' ', $lex), implode(' ', $codes)];
    }
}
