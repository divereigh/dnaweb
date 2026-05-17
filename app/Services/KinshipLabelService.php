<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves dna_matches_kinships rows to human-friendly labels via the
 * relationships table.
 *
 * relationships has multiple rows per nspath, distinguished by gender:
 *   gender = 'M' or 'F' : gender-specific label (e.g. "uncle", "niece")
 *   gender = NULL       : combined / gender-neutral label (e.g. "niece/nephew")
 *
 * The right label for a row depends on the *other party's* effective
 * gender — the kinship is written from sample1's perspective, so the
 * subject of "uncle / aunt / niece / nephew" is sample2.
 *
 * The relationships table is small (~400 rows) so we load it whole
 * into a PHP map once per service instance and resolve labels in
 * memory.
 */
class KinshipLabelService
{
    private ?array $relMap = null;

    /** @return array<string, array<string, string>> nspath => gender('M'|'F'|'_') => label */
    private function relationships(): array
    {
        if ($this->relMap === null) {
            $this->relMap = [];
            foreach (DB::select('SELECT nspath, gender, label FROM relationships') as $r) {
                $key = $r->gender === null || $r->gender === '' ? '_' : $r->gender;
                $this->relMap[$r->nspath][$key] = $r->label ?? '';
            }
        }
        return $this->relMap;
    }

    /**
     * Best label for one nspath given the subject's effective gender
     * ('M', 'F', or '' for unknown). Falls back through:
     *   1. exact gender match (M or F)
     *   2. neutral / NULL-gender variant
     *   3. any variant we have
     *   4. the raw nspath
     */
    public function labelFor(string $nspath, string $effectiveGender): string
    {
        $map = $this->relationships();
        $entries = $map[$nspath] ?? null;
        if (!$entries) {
            return $nspath;
        }
        $g = strtoupper(trim($effectiveGender));
        if ($g !== '' && isset($entries[$g]) && $entries[$g] !== '') {
            return $entries[$g];
        }
        if (isset($entries['_']) && $entries['_'] !== '') {
            return $entries['_'];
        }
        $any = reset($entries);
        return $any !== false && $any !== '' ? $any : $nspath;
    }

    /**
     * Fetch nspath codes for many (sample1, sample2) pairs in one
     * query. Returns a map keyed "sample1:sample2" => list of nspath
     * strings (ordered by the loader's `ordinal`).
     *
     * @param array<int, array{0:int, 1:int}> $pairs
     * @return array<string, string[]>
     */
    public function fetchNspaths(array $pairs): array
    {
        if (!$pairs) {
            return [];
        }
        $tuples = [];
        $bind = [];
        foreach ($pairs as [$s1, $s2]) {
            $tuples[] = '(?, ?)';
            $bind[] = (int) $s1;
            $bind[] = (int) $s2;
        }
        $rows = DB::select(
            'SELECT sample1, sample2, ordinal, nspath
               FROM dna_matches_kinships
              WHERE (sample1, sample2) IN (' . implode(',', $tuples) . ')
              ORDER BY sample1, sample2, ordinal',
            $bind,
        );
        $out = [];
        foreach ($rows as $r) {
            $out["{$r->sample1}:{$r->sample2}"][] = $r->nspath;
        }
        return $out;
    }

    /**
     * Decorate an array of row arrays in place. Each row gets a
     * `kinships` field — a list of friendly labels (one per nspath
     * Ancestry returned). When no predicted kinships exist for the
     * row, `kinships` is set to an empty array.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string $sample1Field key holding the kinship's sample1 id
     * @param string $sample2Field key holding the kinship's sample2 id
     * @param string $genderField  key holding sample2's effective gender
     */
    public function decorate(array &$rows, string $sample1Field, string $sample2Field, string $genderField): void
    {
        if (!$rows) {
            return;
        }
        $pairs = [];
        foreach ($rows as $r) {
            $s1 = (int) ($r[$sample1Field] ?? 0);
            $s2 = (int) ($r[$sample2Field] ?? 0);
            if ($s1 && $s2) {
                $pairs[] = [$s1, $s2];
            }
        }
        $nspathMap = $this->fetchNspaths($pairs);
        foreach ($rows as &$row) {
            $key = ($row[$sample1Field] ?? 0) . ':' . ($row[$sample2Field] ?? 0);
            $g = (string) ($row[$genderField] ?? '');
            $labels = [];
            foreach ($nspathMap[$key] ?? [] as $nspath) {
                $labels[] = $this->labelFor($nspath, $g);
            }
            $row['kinships'] = $labels;
        }
    }
}
