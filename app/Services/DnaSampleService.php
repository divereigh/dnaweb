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
              x.photoUrl,
              x.gender,
              x.userUUID,
              x.admin_userUUID,
              x.createdDate,
              x.managed,
              x.person_id,
              x.person_name,
              x.person_gender
            FROM (
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
                p.id AS person_id,
                p.fullName AS person_name,
                p.gender AS person_gender
              FROM dna_samples s
              LEFT JOIN people p ON p.dnaSampleId = s.id
              LEFT JOIN dna_samples admin ON admin.id = s.adminid
              WHERE s.disabled = 0
                AND s.displayName LIKE ?

              UNION DISTINCT

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
                p.id AS person_id,
                p.fullName AS person_name,
                p.gender AS person_gender
              FROM people p
              JOIN dna_samples s ON s.id = p.dnaSampleId
              LEFT JOIN dna_samples admin ON admin.id = s.adminid
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
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['gender'] ?? null);
            return $row;
        }, $rows);
    }

    public function get(int $sampleId): ?array
    {
        $row = DB::selectOne('
            SELECT
              s.id, s.dnaUUID, s.displayName, s.gender, s.createdDate, s.managed, s.disabled,
              s.photoUrl,
              s.paternalCluster,
              s.userUUID,
              admin.userUUID AS admin_userUUID,
              p.id AS person_id,
              p.fullName AS person_name,
              p.minBirth AS person_minBirth,
              p.maxBirth AS person_maxBirth,
              p.death AS person_death,
              p.gender AS person_gender
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE s.id = ? AND s.disabled = 0
        ', [$sampleId]);

        if (!$row) {
            return null;
        }
        $r = (array) $row;
        $r['display_label'] = Format::displayLabel($r['person_name'] ?? null, $r['displayName'] ?? null);
        $r['created_fmt'] = Format::createdDate($r['createdDate'] ?? null);
        $r['effective_gender'] = Format::effectiveGender($r['person_gender'] ?? null, $r['gender'] ?? null);
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

    /**
     * Are the Perl loaders still actively loading match-of-match data
     * for this sample? Used to decide whether to show a "loading…"
     * indicator below the eye picker. True when, for at least one
     * managed eye that matches this sample, dna_match2match_loaded
     * either has no row, has no totalPages yet, or is partway through
     * pagination, AND hasn't failed.
     */
    public function loadingInProgress(int $sampleId): bool
    {
        // v_pending_match2match captures the "still has pages to fetch
        // for an enabled (eye, other, session) triple" predicate.
        // We add the don't-include-failed filter here.
        $row = DB::selectOne('
            SELECT 1 AS x
            FROM v_pending_match2match
            WHERE othsample = ?
              AND (fail IS NULL OR fail = 0)
            LIMIT 1
        ', [$sampleId]);
        return $row !== null;
    }

    /**
     * Every match of this sample that is itself a managed eye, in the
     * same row shape as listMatches() — no pagination. Used to render
     * the "matching eyes" picker at the top of the matches page.
     */
    public function listEyeMatches(int $sampleId): array
    {
        $rows = DB::select('
            SELECT
              m.sample2 AS other_id,
              s.dnaUUID AS other_uuid,
              s.displayName AS other_name,
              s.managed AS other_managed,
              s.gender AS other_gender,
              s.createdDate AS other_createdDate,
              s.photoUrl AS other_photoUrl,
              s.userUUID AS other_userUUID,
              admin.userUUID AS other_admin_userUUID,
              p.id AS person_id,
              p.fullName AS person_name,
              p.gender AS person_gender,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              m.matchClusterCode,
              m.ignored
            FROM dna_matches2 m
            JOIN dna_samples s ON s.id = m.sample2
              AND s.managed IS NOT NULL
              AND s.disabled = 0
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE m.sample1 = ?
            ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
        ', [$sampleId]);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
            return $row;
        }, $rows);
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
              m.dnapath
            FROM dna_matches2 m
            ' . $eyeJoin . '
            JOIN dna_samples s ON s.id = m.sample2
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE m.sample1 = ?
            ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
            LIMIT ? OFFSET ?
        ', $bind);

        return array_map(function ($r) {
            $row = (array) $r;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
            return $row;
        }, $rows);
    }
}
