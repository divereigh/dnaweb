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
              s.photoUrl,
              s.gender,
              s.createdDate,
              s.managed,
              p.id AS person_id,
              p.fullName AS person_name,
              p.gender AS person_gender
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            WHERE s.managed IS NOT NULL
              AND s.disabled = 0
            ORDER BY s.displayName, s.id
        ');

        // Step 2: bulk aggregate match counts for those eyes only.
        // dna_matches2 is directional — each eye's matches sit in rows
        // where sample1 = eye, so a single GROUP BY does the count.
        $ids = array_map(fn ($r) => $r->id, $eyes);
        $countsBySample = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select("
                SELECT sample1 AS sample_id, COUNT(*) AS c
                FROM dna_matches2
                WHERE sample1 IN ($placeholders)
                GROUP BY sample1
            ", $ids);
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
              s.photoUrl,
              s.managed,
              s.gender,
              s.paternalCluster,
              s.createdDate,
              p.id AS person_id,
              p.fullName AS person_name,
              p.minBirth AS person_minBirth,
              p.maxBirth AS person_maxBirth,
              p.death AS person_death,
              p.gender AS person_gender
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
        $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['gender'] ?? null);
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
        // dna_matches2 is directional: sample1 = viewer. From the eye's
        // perspective, "their" cluster codes live on rows where sample1 = eye.
        $rows = DB::select('
            SELECT DISTINCT m.matchClusterCode AS code
            FROM dna_matches2 m
            WHERE m.sample1 = ?
              AND m.matchClusterCode IS NOT NULL
              AND m.matchClusterCode <> \'\'
            ORDER BY m.matchClusterCode
        ', [$eyeId]);

        return array_map(fn ($r) => $r->code, $rows);
    }

    public function getMatchSummary(int $eyeId, int $otherId): ?array
    {
        // Directional: the match row from eye's perspective is exactly
        // (sample1 = eye, sample2 = other).
        $sql = '
            SELECT
              eye.id AS eye_id,
              eye.displayName AS eye_name,
              eye.photoUrl AS eye_photoUrl,
              eye.gender AS eye_gender,
              peye.fullName AS eye_person_name,
              peye.gender AS eye_person_gender,
              other.id AS other_id,
              other.displayName AS other_name,
              other.dnaUUID AS other_uuid,
              other.photoUrl AS other_photoUrl,
              other.gender AS other_gender,
              pother.id AS other_person_id,
              pother.fullName AS other_person_name,
              pother.gender AS other_person_gender,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              m.matchClusterCode,
              m.predictedKinships,
              m.ignored,
              n.notes
            FROM dna_matches2 m
            JOIN dna_samples eye   ON eye.id   = m.sample1
            JOIN dna_samples other ON other.id = m.sample2
            LEFT JOIN people peye   ON peye.dnaSampleId   = eye.id
            LEFT JOIN people pother ON pother.dnaSampleId = other.id
            LEFT JOIN dna_notes n   ON n.sample = other.id AND n.mgmtsample = eye.id
            WHERE m.sample1 = ? AND m.sample2 = ?
        ';

        $rows = DB::select($sql, [$eyeId, $otherId]);
        if (!$rows) {
            return null;
        }

        $row = (array) $rows[0];
        $row['eye_display_label'] = Format::displayLabel($row['eye_person_name'] ?? null, $row['eye_name'] ?? null);
        $row['other_display_label'] = Format::displayLabel($row['other_person_name'] ?? null, $row['other_name'] ?? null);
        $row['eye_effective_gender'] = Format::effectiveGender($row['eye_person_gender'] ?? null, $row['eye_gender'] ?? null);
        $row['other_effective_gender'] = Format::effectiveGender($row['other_person_gender'] ?? null, $row['other_gender'] ?? null);
        return $row;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function matchesBaseQuery(int $eyeId, string $search, int $hasNotes, int $hideIgnored, int $onlyEyes, string $cluster, bool $withCols): array
    {
        // dna_matches2 is directional. From an eye's perspective, every
        // match they see is exactly one row with sample1 = eye and
        // sample2 = the other party. No UNION/CASE acrobatics needed.
        // The per-direction matchClusterCode + predictedKinships on m
        // are this eye's view.
        $cols = $withCols
            ? '
              m.sample2 AS other_id,
              s.dnaUUID AS other_uuid,
              s.displayName AS other_name,
              s.managed AS other_managed,
              s.gender AS other_gender,
              s.createdDate AS other_createdDate,
              s.photoUrl AS other_photoUrl,
              p.id AS person_id,
              p.fullName AS person_name,
              p.minBirth AS person_minBirth,
              p.maxBirth AS person_maxBirth,
              p.death AS person_death,
              p.gender AS person_gender,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              m.matchClusterCode,
              m.predictedKinships,
              m.ignored,
              n.notes,
              n.loaded AS note_loaded
            '
            : '
              s.displayName AS other_name,
              s.managed AS other_managed,
              m.matchClusterCode,
              m.ignored,
              n.notes
            ';

        $peopleJoin = $withCols
            ? 'LEFT JOIN people p ON p.dnaSampleId = m.sample2'
            : '';

        $sql = "
            SELECT * FROM (
              SELECT $cols
              FROM dna_matches2 m
              JOIN dna_samples s ON s.id = m.sample2
              $peopleJoin
              LEFT JOIN dna_notes n ON n.sample = m.sample2 AND n.mgmtsample = ?
              WHERE m.sample1 = ?
            ) q
            WHERE 1=1
        ";

        $bind = [$eyeId, $eyeId]; // dna_notes mgmtsample, m.sample1

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
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['gender'] ?? null);
            return $row;
        }, $rows);
    }

    private function decorateMatchRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
        }
        return $rows;
    }
}
