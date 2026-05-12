<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class CommonMatchService
{
    public const ALLOWED_PER_PAGE = [25, 50, 100, 200];

    public function count(int $eyeId, int $otherId): int
    {
        // dna_matches2 is directional. People in common with both eye and
        // other are exactly the intersection of {eye's sample2's} and
        // {other's sample2's}. Self-join on sample2.
        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_matches2 ex
            JOIN dna_matches2 mx ON mx.sample1 = ? AND mx.sample2 = ex.sample2
            WHERE ex.sample1 = ?
              AND ex.sample2 <> ?
              AND ex.sample2 <> ?
        ', [$otherId, $eyeId, $eyeId, $otherId]);

        return (int) ($row?->c ?? 0);
    }

    public function list(int $eyeId, int $otherId, int $limit, int $offset): array
    {
        $rows = DB::select('
            SELECT
              ex.sample2 AS other_id,
              sx.displayName,
              sx.dnaUUID,
              sx.gender,
              sx.createdDate,
              sx.managed,
              p.id AS person_id,
              p.fullName AS person_name,
              ex.sharedCentimorgans AS cm_to_eye,
              ex.numSharedSegments  AS segs_to_eye,
              ex.matchClusterCode   AS matchClusterCode,
              mx.sharedCentimorgans AS cm_to_match,
              mx.numSharedSegments  AS segs_to_match,
              n.notes
            FROM dna_matches2 ex
            JOIN dna_matches2 mx
              ON mx.sample1 = ? AND mx.sample2 = ex.sample2
            JOIN dna_samples sx ON sx.id = ex.sample2
            LEFT JOIN people p ON p.dnaSampleId = sx.id
            LEFT JOIN dna_notes n ON n.sample = sx.id AND n.mgmtsample = ?
            WHERE ex.sample1 = ?
              AND ex.sample2 <> ?
              AND ex.sample2 <> ?
            ORDER BY mx.sharedCentimorgans DESC,
                     ex.sharedCentimorgans DESC,
                     sx.displayName
            LIMIT ? OFFSET ?
        ', [$otherId, $eyeId, $eyeId, $eyeId, $otherId, $limit, $offset]);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['created_fmt'] = Format::createdDate($row['createdDate'] ?? null);
            $row['cluster_class'] = Format::clusterClass($row['matchClusterCode'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['displayName'] ?? null);
            return $row;
        }, $rows);
    }
}
