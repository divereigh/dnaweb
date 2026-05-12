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
              s.photoUrl,
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
        // dna_matches2 is directional: rows where sample1 = X are exactly
        // X's view of its matches. Eye-filter becomes a JOIN to the eye's
        // own rows by sample2 (the other party they share).
        $bind = [];
        $eyeJoin = '';
        if ($commonWithEye) {
            $eyeJoin = '
                JOIN dna_matches2 eyem ON eyem.sample1 = ? AND eyem.sample2 = m.sample2
            ';
            $bind[] = $commonWithEye;
        }
        $bind[] = $sampleId;

        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_matches2 m
            ' . $eyeJoin . '
            WHERE m.sample1 = ?
        ', $bind);
        return (int) ($row?->c ?? 0);
    }

    public function listMatches(int $sampleId, int $page, int $pageSize, ?int $commonWithEye = null): array
    {
        $offset = max($page - 1, 0) * $pageSize;
        $bind = [];

        $eyeJoin = '';
        if ($commonWithEye) {
            $eyeJoin = '
                JOIN dna_matches2 eyem ON eyem.sample1 = ? AND eyem.sample2 = m.sample2
            ';
            $bind[] = $commonWithEye;
        }

        $bind[] = $sampleId;        // m.sample1 = ?
        $bind[] = $pageSize;
        $bind[] = $offset;

        $rows = DB::select('
            SELECT
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
              m.dnapath
            FROM dna_matches2 m
            ' . $eyeJoin . '
            JOIN dna_samples s ON s.id = m.sample2
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            WHERE m.sample1 = ?
            ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
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
