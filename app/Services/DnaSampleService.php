<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class DnaSampleService
{
    public function search(string $q, int $limit, int $offset): array
    {
        if ($q === '') {
            return [];
        }
        $like = "%{$q}%";
        $rows = DB::select('
            SELECT
              x.id,
              x.dnaUUID,
              x.displayName,
              x.createdDate,
              x.managed,
              x.person_id,
              x.person_name
            FROM (
              SELECT
                s.id,
                s.dnaUUID,
                s.displayName,
                s.createdDate,
                s.managed,
                p.id AS person_id,
                p.fullName AS person_name
              FROM dna_samples s
              LEFT JOIN people p ON p.dnaSampleId = s.id
              WHERE s.disabled = 0
                AND s.displayName LIKE ?

              UNION DISTINCT

              SELECT
                s.id,
                s.dnaUUID,
                s.displayName,
                s.createdDate,
                s.managed,
                p.id AS person_id,
                p.fullName AS person_name
              FROM people p
              JOIN dna_samples s ON s.id = p.dnaSampleId
              WHERE s.disabled = 0
                AND p.fullName LIKE ?
            ) x
            ORDER BY x.displayName, x.id
            LIMIT ? OFFSET ?
        ', [$like, $like, $limit, $offset]);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
            $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
            return $row;
        }, $rows);
    }

    public function get(int $sampleId): ?array
    {
        $row = DB::selectOne('
            SELECT
              s.id, s.dnaUUID, s.displayName, s.gender, s.createdDate, s.managed, s.disabled,
              p.id AS person_id,
              p.fullName AS person_name,
              p.minBirth AS person_minBirth,
              p.maxBirth AS person_maxBirth,
              p.death AS person_death,
              p.gender AS person_gender
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            WHERE s.id = ? AND s.disabled = 0
        ', [$sampleId]);

        if (!$row) {
            return null;
        }
        $r = (array) $row;
        $r['display_label'] = Format::displayLabel($r['person_name'] ?? null, $r['displayName'] ?? null);
        $r['created_fmt'] = Format::createdDate($r['createdDate'] ?? null);
        return $r;
    }

    public function countMatches(int $sampleId, ?int $commonWithEye = null): int
    {
        // Inner UNION ALL normalises orientation so the optional EXISTS
        // can reference m.other_id directly (no CASE-WHEN), and so the
        // optimiser can use indexes on dna_matches.sample1 / .sample2.
        $sql = '
            SELECT COUNT(*) AS c FROM (
              SELECT sample2 AS other_id FROM dna_matches WHERE sample1 = ?
              UNION ALL
              SELECT sample1 AS other_id FROM dna_matches WHERE sample2 = ?
            ) m
        ';
        $bind = [$sampleId, $sampleId];

        if ($commonWithEye) {
            $sql .= ' WHERE EXISTS (
                SELECT 1 FROM dna_matches em
                WHERE (em.sample1 = ? AND em.sample2 = m.other_id)
                   OR (em.sample2 = ? AND em.sample1 = m.other_id)
            )';
            $bind[] = $commonWithEye;
            $bind[] = $commonWithEye;
        }

        $row = DB::selectOne($sql, $bind);
        return (int) ($row?->c ?? 0);
    }

    public function listMatches(int $sampleId, int $page, int $pageSize, ?int $commonWithEye = null): array
    {
        $offset = max($page - 1, 0) * $pageSize;
        $bind = [$sampleId, $sampleId]; // inner sample1, inner sample2

        $existsClause = '';
        if ($commonWithEye) {
            $existsClause = ' WHERE EXISTS (
                SELECT 1 FROM dna_matches em
                WHERE (em.sample1 = ? AND em.sample2 = q.other_id)
                   OR (em.sample2 = ? AND em.sample1 = q.other_id)
            )';
            $bind[] = $commonWithEye;
            $bind[] = $commonWithEye;
        }

        $bind[] = $pageSize;
        $bind[] = $offset;

        // Inner UNION ALL → column-to-column joins to dna_samples / people.
        // Avoids the CASE-WHEN expression-join that prevented index use.
        $rows = DB::select('
            SELECT * FROM (
              SELECT
                m.other_id,
                s.dnaUUID AS other_uuid,
                s.displayName AS other_name,
                s.managed AS other_managed,
                s.gender AS other_gender,
                s.createdDate AS other_createdDate,
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
                m.ignored,
                m.dnapath
              FROM (
                SELECT sample2 AS other_id, sharedCentimorgans, numSharedSegments,
                       meiosis, matchClusterCode, ignored, dnapath
                FROM dna_matches WHERE sample1 = ?
                UNION ALL
                SELECT sample1 AS other_id, sharedCentimorgans, numSharedSegments,
                       meiosis, matchClusterCode, ignored, dnapath
                FROM dna_matches WHERE sample2 = ?
              ) m
              JOIN dna_samples s ON s.id = m.other_id
              LEFT JOIN people p ON p.dnaSampleId = m.other_id
            ) q' . $existsClause . '
            ORDER BY q.sharedCentimorgans DESC, q.other_id ASC
            LIMIT ? OFFSET ?
        ', $bind);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['cluster_class'] = Format::clusterClass($row['matchClusterCode'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            return $row;
        }, $rows);
    }
}
