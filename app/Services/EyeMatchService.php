<?php

namespace App\Services;

use App\Support\Format;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

class EyeMatchService
{
    public const ALLOWED_PER_PAGE = [25, 50, 100, 200];

    private const SORT_MAP = [
        'name' => 'other_name',
        'cm' => 'sharedCentimorgans',
        'segments' => 'numSharedSegments',
        'meiosis' => 'meiosis',
        'cluster' => 'matchClusterCode',
        'created' => 'other_createdDate',
    ];

    public function __construct(
        private KinshipLabelService $kinship,
        private EyeSetService $eyeSet,
    ) {}

    public function listEyes(): array
    {
        // Step 1: the eye set (small, fast). Looking the ids up by primary
        // key keeps this at the ~114 rows it should be; the same rule
        // written as an OR in the WHERE clause makes the optimiser scan
        // all 2.6M rows of dna_samples.
        $ids = $this->eyeSet->ids();
        if (! $ids) {
            return [];
        }
        $eyes = DB::select('
            SELECT
              s.id,
              s.dnaUUID,
              s.displayName,
              s.photoUrl,
              s.gender,
              s.userUUID,
              admin.userUUID AS admin_userUUID,
              s.createdDate,
              s.managed,
              s.paternalCluster AS paternalCluster_ancestry,
              s.paternalClusterOverride,
              '.Sql::effectivePaternalCluster('s').',
              p.id AS person_id,
              p.fullName AS person_name,
              p.gender AS person_gender
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE s.id IN ('.implode(',', $ids).')
              AND s.disabled = 0
            ORDER BY s.displayName, s.id
        ');

        // Step 2: bulk aggregate match counts for those eyes only.
        // dna_matches2 is directional — each eye's matches sit in rows
        // where sample1 = eye, so a single GROUP BY does the count.
        //
        // The two cluster tallies ride along on the same scan and measured
        // free next to the COUNT(*): the ParentSide-mapping column on /eyes
        // is only meaningful for an eye that actually has clustered
        // matches, and an eye with clusters but no mapping is exactly the
        // row worth flagging. Asking idx_sample1_cluster for them in a
        // second query instead costs another ~2.8s.
        $ids = array_map(fn ($r) => $r->id, $eyes);
        $countsBySample = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select("
                SELECT sample1 AS sample_id,
                       COUNT(*) AS c,
                       SUM(matchClusterCode = 'p1') AS p1,
                       SUM(matchClusterCode = 'p2') AS p2
                FROM dna_matches2
                WHERE sample1 IN ($placeholders)
                GROUP BY sample1
            ", $ids);
            foreach ($rows as $r) {
                $countsBySample[$r->sample_id] = $r;
            }
        }

        $eyes = array_map(function ($r) use ($countsBySample) {
            $row = (array) $r;
            $agg = $countsBySample[$row['id']] ?? null;
            $row['match_count'] = $agg ? (int) $agg->c : 0;
            $row['cluster_p1_count'] = $agg ? (int) $agg->p1 : 0;
            $row['cluster_p2_count'] = $agg ? (int) $agg->p2 : 0;

            return $row;
        }, $eyes);

        return $this->decorateEyeRows($eyes);
    }

    /**
     * The strongest matches on each side of an eye's p1 / p2 split, so the
     * ParentSide-mapping editor on /eyes can be answered rather than
     * guessed at. Ancestry never told us which cluster is the paternal one
     * for these kits — that is the whole reason the override exists — but
     * the top of each cluster is usually a recognisable close relative, and
     * "which of these two lists is Dad's side?" is a question a human can
     * answer in a second.
     *
     * Two queries rather than one windowed pass: idx_sample1_cm is
     * (sample1, sharedCentimorgans), so each one walks a handful of index
     * entries backwards and stops at the LIMIT.
     *
     * @return array{p1: array<array<string, mixed>>, p2: array<array<string, mixed>>}
     */
    public function clusterEvidence(int $eyeId, int $perCluster = 8): array
    {
        $out = [];
        foreach (['p1', 'p2'] as $code) {
            $rows = array_map(fn ($r) => (array) $r, DB::select('
                SELECT
                  m.sample1,
                  m.sample2 AS other_id,
                  m.sharedCentimorgans,
                  m.parentSide,
                  s.displayName AS other_name,
                  s.gender AS other_gender,
                  s.photoUrl AS other_photoUrl,
                  '.$this->eyeSet->sqlIn('s.id').' AS other_is_eye,
                  p.id AS person_id,
                  p.fullName AS person_name,
                  p.gender AS person_gender
                FROM dna_matches2 m
                JOIN dna_samples s ON s.id = m.sample2 AND s.disabled = 0
                LEFT JOIN people p ON p.dnaSampleId = m.sample2
                WHERE m.sample1 = ?
                  AND m.matchClusterCode = ?
                ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
                LIMIT '.(int) $perCluster.'
            ', [$eyeId, $code]));

            foreach ($rows as &$row) {
                $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
                $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
                $row['other_is_eye'] = (bool) $row['other_is_eye'];
            }
            unset($row);

            $this->kinship->decorate($rows, 'sample1', 'other_id', 'effective_gender');
            $out[$code] = $rows;
        }

        return $out;
    }

    public function getEye(int $eyeId): ?array
    {
        if (! $this->eyeSet->contains($eyeId)) {
            return null;
        }

        $rows = DB::select('
            SELECT
              s.id,
              s.dnaUUID,
              s.displayName,
              s.photoUrl,
              s.managed,
              s.gender,
              '.Sql::effectivePaternalCluster('s').',
              s.userUUID,
              admin.userUUID AS admin_userUUID,
              s.createdDate,
              p.id AS person_id,
              p.fullName AS person_name,
              p.minBirth AS person_minBirth,
              p.maxBirth AS person_maxBirth,
              p.death AS person_death,
              p.gender AS person_gender
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE s.id = ?
              AND s.disabled = 0
        ', [$eyeId]);

        if (! $rows) {
            return null;
        }
        $row = (array) $rows[0];
        $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
        $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
        $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['gender'] ?? null);
        $row['has_session'] = $row['managed'] !== null;

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

        return $this->decorateMatchRows($rows, $eyeId);
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
            LEFT JOIN dna_sample_notes n ON n.sample = other.id
            WHERE m.sample1 = ? AND m.sample2 = ?
        ';

        $rows = DB::select($sql, [$eyeId, $otherId]);
        if (! $rows) {
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
        // "Is an eye" is the union set, not just a live session.
        $eyeFlag = $this->eyeSet->sqlIn('s.id');

        $cols = $withCols
            ? "
              m.sample2 AS other_id,
              s.dnaUUID AS other_uuid,
              s.displayName AS other_name,
              s.managed AS other_managed,
              $eyeFlag AS other_is_eye,
              s.gender AS other_gender,
              s.createdDate AS other_createdDate,
              s.photoUrl AS other_photoUrl,
              s.userUUID AS other_userUUID,
              admin.userUUID AS other_admin_userUUID,
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
            "
            : "
              s.displayName AS other_name,
              s.managed AS other_managed,
              $eyeFlag AS other_is_eye,
              m.matchClusterCode,
              m.ignored,
              n.notes
            ";

        $peopleJoin = $withCols
            ? 'LEFT JOIN people p ON p.dnaSampleId = m.sample2'
            : '';
        $adminJoin = $withCols
            ? 'LEFT JOIN dna_samples admin ON admin.id = s.adminid'
            : '';

        $sql = "
            SELECT * FROM (
              SELECT $cols
              FROM dna_matches2 m
              JOIN dna_samples s ON s.id = m.sample2
              $peopleJoin
              $adminJoin
              LEFT JOIN dna_sample_notes n ON n.sample = m.sample2
              WHERE m.sample1 = ?
            ) q
            WHERE 1=1
        ";

        $bind = [$eyeId]; // m.sample1

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
            $sql .= ' AND q.other_is_eye = 1';
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
            // Distinguishes a live eye from one we can still browse but no
            // longer fetch through. Nothing can be loaded for the latter
            // until its Ancestry access is restored and managed is reset.
            $row['has_session'] = $row['managed'] !== null;

            return $row;
        }, $rows);
    }

    private function decorateMatchRows(array $rows, int $eyeId): array
    {
        foreach ($rows as &$row) {
            $row['sample1'] = $eyeId;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
        }
        unset($row);
        $this->kinship->decorate($rows, 'sample1', 'other_id', 'effective_gender');

        return $rows;
    }
}
