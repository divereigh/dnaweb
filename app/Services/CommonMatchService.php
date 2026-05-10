<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class CommonMatchService
{
    public const ALLOWED_PER_PAGE = [25, 50, 100, 200];

    public function count(int $eyeId, int $otherId): int
    {
        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM (
              SELECT sample2 AS other_id FROM dna_matches WHERE sample1 = ?
              UNION ALL
              SELECT sample1 AS other_id FROM dna_matches WHERE sample2 = ?
            ) ex
            JOIN (
              SELECT sample2 AS other_id FROM dna_matches WHERE sample1 = ?
              UNION ALL
              SELECT sample1 AS other_id FROM dna_matches WHERE sample2 = ?
            ) mx ON ex.other_id = mx.other_id
            WHERE ex.other_id <> ? AND ex.other_id <> ?
        ', [$eyeId, $eyeId, $otherId, $otherId, $eyeId, $otherId]);

        return (int) ($row?->c ?? 0);
    }

    public function list(int $eyeId, int $otherId, int $limit, int $offset): array
    {
        $rows = DB::select('
            SELECT
              ex.other_id,
              sx.displayName,
              sx.dnaUUID,
              sx.gender,
              sx.createdDate,
              sx.managed,
              p.id AS person_id,
              p.fullName AS person_name,
              ex.sharedCentimorgans AS cm_to_eye,
              ex.numSharedSegments AS segs_to_eye,
              ex.matchClusterCode AS matchClusterCode,
              mx.sharedCentimorgans AS cm_to_match,
              mx.numSharedSegments AS segs_to_match,
              n.notes
            FROM (
              SELECT sample2 AS other_id, sharedCentimorgans, numSharedSegments, matchClusterCode
              FROM dna_matches WHERE sample1 = ?
              UNION ALL
              SELECT sample1 AS other_id, sharedCentimorgans, numSharedSegments, matchClusterCode
              FROM dna_matches WHERE sample2 = ?
            ) ex
            JOIN (
              SELECT sample2 AS other_id, sharedCentimorgans, numSharedSegments
              FROM dna_matches WHERE sample1 = ?
              UNION ALL
              SELECT sample1 AS other_id, sharedCentimorgans, numSharedSegments
              FROM dna_matches WHERE sample2 = ?
            ) mx ON ex.other_id = mx.other_id
            JOIN dna_samples sx ON sx.id = ex.other_id
            LEFT JOIN people p ON p.dnaSampleId = sx.id
            LEFT JOIN dna_notes n ON n.sample = sx.id AND n.mgmtsample = ?
            WHERE ex.other_id <> ? AND ex.other_id <> ?
            ORDER BY mx.sharedCentimorgans DESC,
                     ex.sharedCentimorgans DESC,
                     sx.displayName
            LIMIT ? OFFSET ?
        ', [$eyeId, $eyeId, $otherId, $otherId, $eyeId, $eyeId, $otherId, $limit, $offset]);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
            $row['cluster_class'] = Format::clusterClass($row['matchClusterCode'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
            return $row;
        }, $rows);
    }
}
