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
              p.id AS person_id, p.fullName AS person_name
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

    public function countMatches(int $sampleId): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM dna_matches WHERE sample1 = ? OR sample2 = ?',
            [$sampleId, $sampleId]
        );
        return (int) ($row?->c ?? 0);
    }

    public function listMatches(int $sampleId, int $page, int $pageSize): array
    {
        $offset = max($page - 1, 0) * $pageSize;
        $rows = DB::select('
            SELECT
              CASE WHEN m.sample1 = ? THEN s2.id ELSE s1.id END AS other_id,
              CASE WHEN m.sample1 = ? THEN s2.dnaUUID ELSE s1.dnaUUID END AS other_uuid,
              CASE WHEN m.sample1 = ? THEN s2.displayName ELSE s1.displayName END AS other_name,
              CASE WHEN m.sample1 = ? THEN s2.managed ELSE s1.managed END AS other_managed,
              CASE WHEN m.sample1 = ? THEN s2.gender ELSE s1.gender END AS other_gender,
              CASE WHEN m.sample1 = ? THEN s2.createdDate ELSE s1.createdDate END AS other_createdDate,
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
            FROM dna_matches m
            JOIN dna_samples s1 ON s1.id = m.sample1
            JOIN dna_samples s2 ON s2.id = m.sample2
            LEFT JOIN people p
              ON p.dnaSampleId = CASE WHEN m.sample1 = ? THEN s2.id ELSE s1.id END
            WHERE (m.sample1 = ? OR m.sample2 = ?)
            ORDER BY m.sharedCentimorgans DESC, other_id ASC
            LIMIT ? OFFSET ?
        ', [
            ...array_fill(0, 7, $sampleId),
            $sampleId, $sampleId,
            $pageSize, $offset,
        ]);

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
