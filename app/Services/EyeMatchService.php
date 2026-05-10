<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class EyeMatchService
{
    public const ALLOWED_PER_PAGE = [25, 50, 100, 200];

    private const SORT_MAP = [
        'name'    => 'other_name',
        'cm'      => 'sharedCentimorgans',
        'segments' => 'numSharedSegments',
        'meiosis' => 'meiosis',
        'cluster' => 'matchClusterCode',
        'created' => 'other_createdDate',
    ];

    public function listEyes(): array
    {
        // Step 1: managed kits only (small set, fast).
        $eyes = DB::select('
            SELECT
              s.id,
              s.dnaUUID,
              s.displayName,
              s.gender,
              s.createdDate,
              s.managed,
              p.id AS person_id,
              p.fullName AS person_name
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            WHERE s.managed IS NOT NULL
              AND s.disabled = 0
            ORDER BY s.displayName, s.id
        ');

        // Step 2: bulk aggregate match counts for those eyes only.
        $ids = array_map(fn ($r) => $r->id, $eyes);
        $countsBySample = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select("
                SELECT sample_id, COUNT(*) AS c FROM (
                  SELECT sample1 AS sample_id FROM dna_matches WHERE sample1 IN ($placeholders)
                  UNION ALL
                  SELECT sample2 AS sample_id FROM dna_matches WHERE sample2 IN ($placeholders)
                ) m GROUP BY sample_id
            ", [...$ids, ...$ids]);
            foreach ($rows as $r) {
                $countsBySample[$r->sample_id] = (int) $r->c;
            }
        }

        $eyes = array_map(function ($r) use ($countsBySample) {
            $row = (array) $r;
            $row['match_count'] = $countsBySample[$row['id']] ?? 0;
            return $row;
        }, $eyes);

        return $this->decorateEyeRows($eyes);
    }

    public function getEye(int $eyeId): ?array
    {
        $rows = DB::select('
            SELECT
              s.id,
              s.dnaUUID,
              s.displayName,
              s.managed,
              s.gender,
              s.createdDate,
              p.id AS person_id,
              p.fullName AS person_name
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            WHERE s.id = ?
              AND s.managed IS NOT NULL
              AND s.disabled = 0
        ', [$eyeId]);

        if (!$rows) {
            return null;
        }
        $row = (array) $rows[0];
        $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
        $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
        return $row;
    }

    public function countMatches(int $eyeId, string $search, int $hasNotes, int $hideIgnored, int $onlyEyes, string $cluster): int
    {
        [$sql, $bind] = $this->matchesBaseQuery($eyeId, $search, $hasNotes, $hideIgnored, $onlyEyes, $cluster, withCols: false);
        $count = DB::selectOne("SELECT COUNT(*) AS c FROM ($sql) AS counted", $bind);
        return (int) ($count?->c ?? 0);
    }

    public function listMatches(int $eyeId, string $search, int $hasNotes, int $hideIgnored, int $onlyEyes, string $cluster, string $sort, string $direction, int $limit, int $offset): array
    {
        $sortCol = self::SORT_MAP[$sort] ?? self::SORT_MAP['cm'];
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        [$sql, $bind] = $this->matchesBaseQuery($eyeId, $search, $hasNotes, $hideIgnored, $onlyEyes, $cluster, withCols: true);

        $sql .= " ORDER BY {$sortCol} {$dir}, other_id ASC LIMIT ? OFFSET ?";
        $bind[] = $limit;
        $bind[] = $offset;

        $rows = array_map(fn ($r) => (array) $r, DB::select($sql, $bind));
        return $this->decorateMatchRows($rows);
    }

    public function listClusters(int $eyeId): array
    {
        $rows = DB::select('
            SELECT DISTINCT m.matchClusterCode AS code
            FROM dna_matches m
            WHERE (m.sample1 = ? OR m.sample2 = ?)
              AND m.matchClusterCode IS NOT NULL
              AND m.matchClusterCode <> \'\'
            ORDER BY m.matchClusterCode
        ', [$eyeId, $eyeId]);

        return array_map(fn ($r) => $r->code, $rows);
    }

    public function getMatchSummary(int $eyeId, int $otherId): ?array
    {
        if ($eyeId < $otherId) {
            $ordered = [$eyeId, $otherId, $otherId, $eyeId];
        } else {
            $ordered = [$otherId, $eyeId, $eyeId, $otherId];
        }

        $sql = '
            SELECT
              eye.id AS eye_id,
              eye.displayName AS eye_name,
              peye.fullName AS eye_person_name,
              other.id AS other_id,
              other.displayName AS other_name,
              other.dnaUUID AS other_uuid,
              pother.id AS other_person_id,
              pother.fullName AS other_person_name,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              m.matchClusterCode,
              m.ignored,
              n.notes
            FROM dna_matches m
            JOIN dna_samples eye ON eye.id = ?
            JOIN dna_samples other ON other.id = ?
            LEFT JOIN people peye ON peye.dnaSampleId = eye.id
            LEFT JOIN people pother ON pother.dnaSampleId = other.id
            LEFT JOIN dna_notes n ON n.sample = other.id AND n.mgmtsample = eye.id
            WHERE (m.sample1 = ? AND m.sample2 = ?)
               OR (m.sample1 = ? AND m.sample2 = ?)
        ';

        $rows = DB::select($sql, [$eyeId, $otherId, ...$ordered]);
        if (!$rows) {
            return null;
        }

        $row = (array) $rows[0];
        $row['cluster_class'] = Format::clusterClass($row['matchClusterCode'] ?? null);
        $row['eye_display_label'] = Format::displayLabel($row['eye_person_name'] ?? null, $row['eye_name'] ?? null);
        $row['other_display_label'] = Format::displayLabel($row['other_person_name'] ?? null, $row['other_name'] ?? null);
        return $row;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function matchesBaseQuery(int $eyeId, string $search, int $hasNotes, int $hideIgnored, int $onlyEyes, string $cluster, bool $withCols): array
    {
        // Inner subquery normalises the bidirectional CASE pattern.
        $cols = $withCols
            ? '
              CASE WHEN m.sample1 = ? THEN s2.id ELSE s1.id END AS other_id,
              CASE WHEN m.sample1 = ? THEN s2.dnaUUID ELSE s1.dnaUUID END AS other_uuid,
              CASE WHEN m.sample1 = ? THEN s2.displayName ELSE s1.displayName END AS other_name,
              CASE WHEN m.sample1 = ? THEN s2.managed ELSE s1.managed END AS other_managed,
              CASE WHEN m.sample1 = ? THEN s2.gender ELSE s1.gender END AS other_gender,
              CASE WHEN m.sample1 = ? THEN s2.createdDate ELSE s1.createdDate END AS other_createdDate,
              p.id AS person_id,
              p.fullName AS person_name,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              m.matchClusterCode,
              m.ignored,
              n.notes,
              n.loaded AS note_loaded
            '
            : '
              CASE WHEN m.sample1 = ? THEN s2.displayName ELSE s1.displayName END AS other_name,
              CASE WHEN m.sample1 = ? THEN s2.managed ELSE s1.managed END AS other_managed,
              m.matchClusterCode,
              m.ignored,
              n.notes
            ';

        // Number of `?` placeholders in $cols
        $colsParams = $withCols ? 6 : 2;

        $sql = "
            SELECT * FROM (
              SELECT $cols
              FROM dna_matches m
              JOIN dna_samples s1 ON s1.id = m.sample1
              JOIN dna_samples s2 ON s2.id = m.sample2
              " . ($withCols
                ? '
              LEFT JOIN people p
                ON p.dnaSampleId = CASE WHEN m.sample1 = ? THEN s2.id ELSE s1.id END
              '
                : '') . "
              LEFT JOIN dna_notes n
                ON n.sample = CASE WHEN m.sample1 = ? THEN s2.id ELSE s1.id END
               AND n.mgmtsample = ?
              WHERE (m.sample1 = ? OR m.sample2 = ?)
            ) q
            WHERE 1=1
        ";

        $bind = array_fill(0, $colsParams, $eyeId);
        if ($withCols) {
            $bind[] = $eyeId; // for the people CASE
        }
        $bind[] = $eyeId; // dna_notes CASE
        $bind[] = $eyeId; // dna_notes mgmtsample
        $bind[] = $eyeId; // WHERE sample1
        $bind[] = $eyeId; // WHERE sample2

        if ($search !== '') {
            $sql .= ' AND q.other_name LIKE ?';
            $bind[] = "%{$search}%";
        }
        if ($hasNotes) {
            $sql .= " AND q.notes IS NOT NULL AND q.notes <> ''";
        }
        if ($hideIgnored) {
            $sql .= ' AND q.ignored = 0';
        }
        if ($onlyEyes) {
            $sql .= ' AND q.other_managed IS NOT NULL';
        }
        if ($cluster !== '') {
            $sql .= ' AND q.matchClusterCode = ?';
            $bind[] = $cluster;
        }

        return [$sql, $bind];
    }

    private function decorateEyeRows(array $rows): array
    {
        return array_map(function ($r) {
            $row = (array) $r;
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
            $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
            $row['match_count'] = (int) ($row['match_count'] ?? 0);
            return $row;
        }, $rows);
    }

    private function decorateMatchRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['cluster_class'] = Format::clusterClass($row['matchClusterCode'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
        }
        return $rows;
    }
}
