<?php

namespace App\Services;

use App\Support\Format;
use App\Support\PhoneticEncoder;
use Illuminate\Support\Facades\DB;

class PeopleSearchService
{
    /** @var array<int>|null cached for the lifetime of the request */
    private ?array $eyeIds = null;

    /** @return array<int> ids of all currently-managed eyes */
    private function managedEyeIds(): array
    {
        if ($this->eyeIds === null) {
            $this->eyeIds = array_map(
                fn ($r) => (int) $r->id,
                DB::select('SELECT id FROM dna_samples WHERE managed IS NOT NULL AND managed > 0 AND disabled = 0')
            );
        }
        return $this->eyeIds;
    }

    public function count(string $q, int $linked, int $hasMatches): int
    {
        [$sql, $bind] = $this->baseQuery($q, $linked, $hasMatches, count: true);
        $row = DB::selectOne($sql, $bind);
        return (int) ($row?->c ?? 0);
    }

    public function list(string $q, int $linked, int $hasMatches, string $sort, int $page, int $pageSize): array
    {
        [$sql, $bind] = $this->baseQuery($q, $linked, $hasMatches, count: false);

        $offset = max($page - 1, 0) * $pageSize;
        $sortMap = [
            'name'  => 'p.fullName ASC, p.alt ASC, p.id ASC',
            'dna'   => 'ds.displayName ASC, p.fullName ASC, p.id ASC',
            'eyes'  => 'p.fullName ASC, p.alt ASC, p.id ASC',
            'maxcm' => 'p.fullName ASC, p.alt ASC, p.id ASC',
        ];
        $orderBy = $sortMap[$sort] ?? $sortMap['name'];
        $sql .= " ORDER BY {$orderBy} LIMIT ? OFFSET ?";
        $bind[] = $pageSize;
        $bind[] = $offset;

        $rows = array_map(fn ($r) => (array) $r, DB::select($sql, $bind));
        if (!$rows) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['eye_count'] = 0;
            $row['max_cm'] = 0;
            $row['display_label'] = Format::displayLabel($row['fullName'] ?? null, $row['dnaName'] ?? null);
        }
        unset($row);

        $sampleIds = array_values(array_filter(array_map(fn ($r) => $r['dnaSampleId'] ?? null, $rows)));
        if ($sampleIds) {
            $placeholders = implode(',', array_fill(0, count($sampleIds), '?'));
            // dna_matches2 is directional. Managed-eye matches are stored
            // as rows where sample1 = the eye and sample2 = the matched
            // sample, so this collapses to a single SELECT.
            $stats = DB::select("
                SELECT
                  dm.sample2 AS sample_id,
                  COUNT(DISTINCT dm.sample1) AS eye_count,
                  MAX(dm.sharedCentimorgans) AS max_cm
                FROM dna_matches2 dm
                JOIN dna_samples eye
                  ON eye.id = dm.sample1
                 AND eye.managed IS NOT NULL
                 AND eye.managed > 0
                WHERE dm.sample2 IN ({$placeholders})
                GROUP BY dm.sample2
            ", $sampleIds);

            $by = [];
            foreach ($stats as $s) {
                $by[$s->sample_id] = $s;
            }
            foreach ($rows as &$row) {
                $sid = $row['dnaSampleId'] ?? null;
                if ($sid && isset($by[$sid])) {
                    $row['eye_count'] = (int) ($by[$sid]->eye_count ?? 0);
                    $row['max_cm'] = (int) ($by[$sid]->max_cm ?? 0);
                }
            }
            unset($row);
        }

        if ($sort === 'eyes') {
            usort($rows, fn ($a, $b) => [-$a['eye_count'], $a['fullName'] ?? '', $a['id'] ?? 0]
                <=> [-$b['eye_count'], $b['fullName'] ?? '', $b['id'] ?? 0]);
        } elseif ($sort === 'maxcm') {
            usort($rows, fn ($a, $b) => [-$a['max_cm'], $a['fullName'] ?? '', $a['id'] ?? 0]
                <=> [-$b['max_cm'], $b['fullName'] ?? '', $b['id'] ?? 0]);
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function baseQuery(string $q, int $linked, int $hasMatches, bool $count): array
    {
        $bind = [];
        $where = ['1=1'];
        if ($q !== '') {
            [$lex, $phon] = PhoneticEncoder::buildBoolean($q);
            if ($lex === '' && $phon === '') {
                // No usable search tokens — force an empty result set.
                $where[] = '1=0';
            } else {
                $lex  = $lex  !== '' ? $lex  : '+__never_matches__';
                $phon = $phon !== '' ? $phon : '+__never_matches__';
                $where[] = '(
                    MATCH(p.fullName)              AGAINST (? IN BOOLEAN MODE)
                 OR MATCH(p.fullName_phonetic)     AGAINST (? IN BOOLEAN MODE)
                 OR MATCH(ds.displayName)          AGAINST (? IN BOOLEAN MODE)
                 OR MATCH(ds.displayName_phonetic) AGAINST (? IN BOOLEAN MODE)
                )';
                $bind[] = $lex;
                $bind[] = $phon;
                $bind[] = $lex;
                $bind[] = $phon;
            }
        }
        if ($linked) {
            $where[] = 'p.dnaSampleId IS NOT NULL';
        }

        $select = $count
            ? 'SELECT COUNT(*) AS c'
            : '
                SELECT
                  p.id,
                  p.fullName,
                  p.alt,
                  p.dnaSampleId,
                  ds.displayName AS dnaName
            ';

        $sql = "
            {$select}
            FROM people p
            LEFT JOIN dna_samples ds ON ds.id = p.dnaSampleId
        ";

        if ($hasMatches) {
            // EXISTS against dna_matches2 with an inline IN-list of
            // managed-eye ids. We pre-fetch the eye ids in PHP because
            // putting them as a subquery triggers semi-join materialisation
            // (the optimiser scans 3M rows before the LIKE can narrow the
            // people set).
            $eyeIds = $this->managedEyeIds();
            $where[] = 'p.dnaSampleId IS NOT NULL';
            if (! $eyeIds) {
                $where[] = '1=0'; // no managed eyes → no matches
            } else {
                $eyePlaceholders = implode(',', array_fill(0, count($eyeIds), '?'));
                $where[] = "EXISTS (
                    SELECT 1 FROM dna_matches2 dm
                    WHERE dm.sample2 = p.dnaSampleId
                      AND dm.sample1 IN ($eyePlaceholders)
                )";
                array_push($bind, ...$eyeIds);
            }
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        return [$sql, $bind];
    }
}
