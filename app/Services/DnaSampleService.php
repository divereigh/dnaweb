<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class DnaSampleService
{
    public function __construct(private KinshipLabelService $kinship) {}

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

    public function countMatches(int $sampleId, ?int $commonWithEye = null, string $search = ''): int
    {
        // dna_matches2 is directional: rows where sample1 = X are exactly
        // X's view of its matches. Eye-filter becomes a JOIN to the eye's
        // own rows by sample2 (the other party they share). Search needs
        // the dna_samples/people joins to look at display names.
        $bind = [];
        $eyeJoin = '';
        if ($commonWithEye) {
            $eyeJoin = '
                JOIN dna_matches2 eyem ON eyem.sample1 = ? AND eyem.sample2 = m.sample2
            ';
            $bind[] = $commonWithEye;
        }
        $bind[] = $sampleId;

        $searchJoin = '';
        $searchWhere = '';
        if ($search !== '') {
            $searchJoin = '
                JOIN dna_samples s ON s.id = m.sample2
                LEFT JOIN people p ON p.dnaSampleId = m.sample2
            ';
            $searchWhere = ' AND (s.displayName LIKE ? OR p.fullName LIKE ?)';
            $bind[] = "%{$search}%";
            $bind[] = "%{$search}%";
        }

        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_matches2 m
            ' . $eyeJoin . $searchJoin . '
            WHERE m.sample1 = ?' . $searchWhere
        , $bind);
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
        // Any non-terminal queue row for this sample means the worker
        // either hasn't picked it up yet or is currently loading it.
        $row = DB::selectOne('
            SELECT 1 AS x
            FROM v_pending_match2match
            WHERE othsample = ?
              AND status IN (\'pending\', \'running\')
            LIMIT 1
        ', [$sampleId]);
        return $row !== null;
    }

    /**
     * Force a fresh reload of every (eye, sample) pair for this
     * sample — done, abandoned, and any retry-backoff rows get
     * flipped back to pending with their progress counters cleared.
     * Workers will then re-fetch them from page 1.
     *
     * Skips rows currently `running` (a worker has them); those will
     * complete and write fresh data anyway. Returns the number of
     * rows that were updated.
     */
    public function requeueAll(int $sampleId, int $priority = 10): int
    {
        // Only resurrect rows whose mgmtsample is *still* a loadable
        // eye — managed, enabled, with a session. Without this the
        // RELOAD button can revive rows for eyes that have since been
        // un-managed, and they sit pending forever (workers reject
        // them on the same predicate).
        return DB::update("
            UPDATE dna_match2match_loaded l
              JOIN dna_samples m ON m.id = l.mgmtsample
                                AND m.disabled = 0
                                AND m.managed IS NOT NULL
              JOIN session     s ON s.id = m.managed
               SET l.status        = 'pending',
                   l.lastPage      = NULL,
                   l.totalPages    = NULL,
                   l.success       = 0,
                   l.fail          = 0,
                   l.attempts      = 0,
                   l.claimed_at    = NULL,
                   l.claimed_by    = NULL,
                   l.next_retry_at = NULL,
                   l.enqueued_at   = NOW(),
                   l.priority      = ?
             WHERE l.othsample = ?
               AND l.status <> 'running'
        ", [$priority, $sampleId]);
    }

    /**
     * Idempotently push all not-yet-loaded (eye, sample) pairs onto
     * the queue at web priority (10). Called when a user navigates to
     * /dna/{id}/matches so the worker starts on those pairs first.
     * Done/abandoned pairs are left alone — use requeueAll for those.
     */
    public function enqueueForSample(int $sampleId): void
    {
        // Materialise the candidate pairs first so the INSERT...SELECT
        // doesn't drag the view (which has its own `priority` column)
        // into the ON DUPLICATE KEY UPDATE scope — MariaDB resolves
        // the unqualified `priority` on the UPDATE clause against the
        // source SELECT's columns and complains it is ambiguous.
        $pairs = DB::select('
            SELECT mgmtsample, othsample
            FROM v_pending_match2match
            WHERE othsample = ? AND status = ?
        ', [$sampleId, 'pending']);

        if (!$pairs) {
            return;
        }

        $sql = 'INSERT INTO dna_match2match_loaded
                    (mgmtsample, othsample, status, enqueued_at, priority)
                VALUES ' . implode(',', array_fill(0, count($pairs), '(?, ?, ?, NOW(), ?)')) . '
                ON DUPLICATE KEY UPDATE
                    priority    = LEAST(priority, VALUES(priority)),
                    enqueued_at = COALESCE(enqueued_at, VALUES(enqueued_at))';

        $bind = [];
        foreach ($pairs as $p) {
            $bind[] = $p->mgmtsample;
            $bind[] = $p->othsample;
            $bind[] = 'pending';
            $bind[] = 10;
        }

        DB::statement($sql, $bind);
    }

    /**
     * Every match of this sample that is itself a managed eye, in the
     * same row shape as listMatches() — no pagination. Used to render
     * the "matching eyes" picker at the top of the matches page.
     */
    public function listEyeMatches(int $sampleId): array
    {
        // matchClusterCode / parentSide here are deliberately the
        // *eye's* POV of the title sample — `pov` row is
        // (sample1=eye, sample2=title), and `s.paternalCluster` is
        // the eye's own paternalCluster. That way the ParentSide
        // pill rendered next to each picker entry tells you which
        // side of *that eye's* tree the title falls on.
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
              s.paternalCluster AS paternalCluster,
              admin.userUUID AS other_admin_userUUID,
              p.id AS person_id,
              p.fullName AS person_name,
              p.gender AS person_gender,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.meiosis,
              pov.matchClusterCode AS matchClusterCode,
              pov.parentSide AS parentSide,
              m.ignored
            FROM dna_matches2 m
            JOIN dna_samples s ON s.id = m.sample2
              AND s.managed IS NOT NULL
              AND s.disabled = 0
            LEFT JOIN dna_matches2 pov ON pov.sample1 = m.sample2 AND pov.sample2 = ?
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE m.sample1 = ?
            ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
        ', [$sampleId, $sampleId]);

        $rows = array_map(function ($r) use ($sampleId) {
            $row = (array) $r;
            $row['sample1'] = $sampleId;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
            return $row;
        }, $rows);

        $this->kinship->decorate($rows, 'sample1', 'other_id', 'effective_gender');
        return $rows;
    }

    public function listMatches(int $sampleId, int $page, int $pageSize, ?int $commonWithEye = null, string $search = '', ?int $notesEye = null, ?int $povEye = null): array
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

        // ParentSide / cluster always come from the eye-on-other
        // row (`pov.sample1 = eye, pov.sample2 = other`) so the
        // pill reflects the eye doing the looking, not the title.
        // When $povEye is null (title is non-eye and no eye is
        // selected) both columns return NULL — the pill stays empty.
        $povJoin = '';
        $povCols = 'NULL AS matchClusterCode, NULL AS parentSide';
        if ($povEye) {
            $povJoin = '
                LEFT JOIN dna_matches2 pov ON pov.sample1 = ? AND pov.sample2 = m.sample2
            ';
            $povCols = 'pov.matchClusterCode AS matchClusterCode, pov.parentSide AS parentSide';
            $bind[] = $povEye;
        }

        // dna_notes is keyed by (sample = the "other" party,
        // mgmtsample = the eye doing the noting). $notesEye picks
        // which eye's notes to surface: the title sample if it is
        // itself an eye (the controller's call sets this) — else
        // the eye selected via the filter.
        $notesJoin = '';
        $noteCol = 'NULL AS note';
        if ($notesEye) {
            $notesJoin = '
                LEFT JOIN dna_notes n ON n.sample = m.sample2 AND n.mgmtsample = ?
            ';
            $noteCol = 'n.notes AS note';
            $bind[] = $notesEye;
        }

        $bind[] = $sampleId;        // m.sample1 = ?

        $searchWhere = '';
        if ($search !== '') {
            $searchWhere = ' AND (s.displayName LIKE ? OR p.fullName LIKE ?)';
            $bind[] = "%{$search}%";
            $bind[] = "%{$search}%";
        }

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
              ' . $povCols . ',
              m.predictedKinships,
              m.ignored,
              m.dnapath,
              ' . $noteCol . '
            FROM dna_matches2 m
            ' . $eyeJoin . $povJoin . '
            JOIN dna_samples s ON s.id = m.sample2
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            ' . $notesJoin . '
            WHERE m.sample1 = ?' . $searchWhere . '
            ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
            LIMIT ? OFFSET ?
        ', $bind);

        $rows = array_map(function ($r) use ($sampleId) {
            $row = (array) $r;
            $row['sample1'] = $sampleId;
            $row['created_fmt'] = Format::createdDate($row['other_createdDate'] ?? null);
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['other_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['other_gender'] ?? null);
            return $row;
        }, $rows);

        $this->kinship->decorate($rows, 'sample1', 'other_id', 'effective_gender');
        return $rows;
    }
}
