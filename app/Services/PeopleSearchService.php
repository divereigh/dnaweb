<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class PeopleSearchService
{
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
            $stats = DB::select("
                SELECT
                  pm.sample_id,
                  COUNT(DISTINCT pm.eye_id) AS eye_count,
                  MAX(pm.sharedCentimorgans) AS max_cm
                FROM (
                  SELECT dm.sample2 AS sample_id, dm.sample1 AS eye_id, dm.sharedCentimorgans
                  FROM dna_matches dm
                  JOIN dna_samples eye
                    ON eye.id = dm.sample1
                   AND eye.managed IS NOT NULL
                   AND eye.managed > 0
                  WHERE dm.sample2 IN ({$placeholders})
                  UNION ALL
                  SELECT dm.sample1 AS sample_id, dm.sample2 AS eye_id, dm.sharedCentimorgans
                  FROM dna_matches dm
                  JOIN dna_samples eye
                    ON eye.id = dm.sample2
                   AND eye.managed IS NOT NULL
                   AND eye.managed > 0
                  WHERE dm.sample1 IN ({$placeholders})
                ) pm
                GROUP BY pm.sample_id
            ", [...$sampleIds, ...$sampleIds]);

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
            $where[] = '(p.fullName LIKE ? OR ds.displayName LIKE ?)';
            $bind[] = "%{$q}%";
            $bind[] = "%{$q}%";
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
            $sql .= '
                INNER JOIN (
                  SELECT DISTINCT sample_id
                  FROM (
                    SELECT dm.sample2 AS sample_id
                    FROM dna_matches dm
                    JOIN dna_samples eye
                      ON eye.id = dm.sample1
                     AND eye.managed IS NOT NULL
                     AND eye.managed > 0
                    UNION
                    SELECT dm.sample1 AS sample_id
                    FROM dna_matches dm
                    JOIN dna_samples eye
                      ON eye.id = dm.sample2
                     AND eye.managed IS NOT NULL
                     AND eye.managed > 0
                  ) matched_samples
                ) hm ON hm.sample_id = p.dnaSampleId
            ';
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        return [$sql, $bind];
    }
}
