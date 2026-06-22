<?php

namespace App\Services;

use App\Support\Format;
use App\Support\PhoneticEncoder;
use Illuminate\Support\Facades\DB;

class DnaSampleService
{
    public function __construct(private KinshipLabelService $kinship) {}

    public function search(string $q, int $limit, int $offset): array
    {
        if ($q === '') {
            return [];
        }
        [$lex, $phon] = PhoneticEncoder::buildBoolean($q);
        if ($lex === '' && $phon === '') {
            return [];
        }
        // FT MATCH needs a non-empty BOOLEAN expression on each side;
        // when one side has no usable tokens substitute a sentinel that
        // matches nothing so the SQL stays uniform.
        $lex  = $lex  !== '' ? $lex  : '+__never_matches__';
        $phon = $phon !== '' ? $phon : '+__never_matches__';

        $rows = DB::select('
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
              p.gender AS person_gender,
              (
                MATCH(s.displayName)          AGAINST (? IN BOOLEAN MODE) * 2 +
                MATCH(s.displayName_phonetic) AGAINST (? IN BOOLEAN MODE) +
                COALESCE(MATCH(p.fullName)          AGAINST (? IN BOOLEAN MODE), 0) * 2 +
                COALESCE(MATCH(p.fullName_phonetic) AGAINST (? IN BOOLEAN MODE), 0)
              ) AS score
            FROM dna_samples s
            LEFT JOIN people p ON p.dnaSampleId = s.id
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            WHERE s.disabled = 0
              AND (
                  MATCH(s.displayName)          AGAINST (? IN BOOLEAN MODE)
               OR MATCH(s.displayName_phonetic) AGAINST (? IN BOOLEAN MODE)
               OR MATCH(p.fullName)             AGAINST (? IN BOOLEAN MODE)
               OR MATCH(p.fullName_phonetic)    AGAINST (? IN BOOLEAN MODE)
              )
            ORDER BY score DESC, s.displayName, s.id
            LIMIT ? OFFSET ?
        ', [$lex, $phon, $lex, $phon, $lex, $phon, $lex, $phon, $limit, $offset]);

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

    /**
     * Build the ParentSide WHERE fragment for the match list. $side is
     * one of ALL / PATERNAL / MATERNAL / P1 / P2 (case-insensitive).
     * $alias is the SQL alias of the POV row (the eye-on-other row that
     * carries matchClusterCode / parentSide). $paternalCluster is that
     * eye's own paternalCluster, used to map p1/p2 → PATERNAL/MATERNAL.
     *
     * Mirrors ClusterPill.vue: the parentSide enum is authoritative
     * when it holds one of the four enum values; otherwise the side is
     * derived from matchClusterCode vs the eye's paternalCluster. P1/P2
     * filter on the raw cluster code regardless of resolved side.
     *
     * @return array{0:string,1:array} [sqlFragment, binds]
     */
    private function parentSideFilter(string $side, string $alias, ?string $paternalCluster): array
    {
        $side = strtoupper(trim($side));
        if ($side === '' || $side === 'ALL') {
            return ['', []];
        }
        if ($side === 'P1' || $side === 'P2') {
            return [" AND {$alias}.matchClusterCode = ?", [strtolower($side)]];
        }

        // parentSide enum is authoritative when set to a real value;
        // NULL / empty / anything else falls through to cluster-derived.
        $notAuthoritative = "({$alias}.parentSide IS NULL OR {$alias}.parentSide NOT IN ('PATERNAL','MATERNAL','BOTH','UNASSIGNED'))";
        $pat = strtolower((string) $paternalCluster);
        $patKnown = ($pat === 'p1' || $pat === 'p2');

        if ($side === 'PATERNAL') {
            if (! $patKnown) {
                return [" AND {$alias}.parentSide = 'PATERNAL'", []];
            }
            return [
                " AND ({$alias}.parentSide = 'PATERNAL' OR ({$notAuthoritative} AND {$alias}.matchClusterCode = ?))",
                [$pat],
            ];
        }
        if ($side === 'MATERNAL') {
            if (! $patKnown) {
                return [" AND {$alias}.parentSide = 'MATERNAL'", []];
            }
            $other = $pat === 'p1' ? 'p2' : 'p1';
            return [
                " AND ({$alias}.parentSide = 'MATERNAL' OR ({$notAuthoritative} AND {$alias}.matchClusterCode = ?))",
                [$other],
            ];
        }
        return ['', []];
    }

    /**
     * Build the Trees WHERE fragment: restrict to matches whose person
     * is a member of $treeId. Self-contained EXISTS keyed on m.sample2
     * so it works in both count and list without needing a people join.
     *
     * @return array{0:string,1:array} [sqlFragment, binds]
     */
    private function treeFilter(?int $treeId): array
    {
        if (! $treeId) {
            return ['', []];
        }
        return [
            ' AND EXISTS (
                SELECT 1 FROM people pp
                JOIN tree_people tpf ON tpf.peopleId = pp.id
                WHERE pp.dnaSampleId = m.sample2 AND tpf.treeId = ?
            )',
            [$treeId],
        ];
    }

    /**
     * Distinct trees across all of this sample's matches — the stable
     * option list for the Trees filter dropdown. Independent of the
     * current page / active filters so the dropdown doesn't shrink as
     * you filter. Returns [{id, name, letter, colour}], priority order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function treeOptionsForSample(int $sampleId): array
    {
        $rows = DB::select('
            SELECT DISTINCT t.id, t.name, t.colour
            FROM dna_matches2 m
            JOIN people pp     ON pp.dnaSampleId = m.sample2
            JOIN tree_people tp ON tp.peopleId = pp.id
            JOIN tree t         ON t.id = tp.treeId
            WHERE m.sample1 = ?
            ORDER BY t.priority DESC, t.name ASC
        ', [$sampleId]);

        return array_map(fn ($r) => [
            'id'     => (int) $r->id,
            'name'   => $r->name,
            'letter' => mb_strtoupper(mb_substr((string) $r->name, 0, 1)),
            'colour' => $r->colour,
        ], $rows);
    }

    public function countMatches(int $sampleId, ?int $commonWithEye = null, string $search = '', ?int $povEye = null, string $parentSide = '', ?string $povPaternalCluster = null, ?int $treeId = null): int
    {
        // dna_matches2 is directional: rows where sample1 = X are exactly
        // X's view of its matches. Eye-filter becomes a JOIN to the eye's
        // own rows by sample2 (the other party they share). Search uses
        // FULLTEXT MATCH on the joined sample / person name + phonetic.
        $bind = [];
        $eyeJoin = '';
        if ($commonWithEye) {
            $eyeJoin = '
                JOIN dna_matches2 eyem ON eyem.sample1 = ? AND eyem.sample2 = m.sample2
            ';
            $bind[] = $commonWithEye;
        }

        // ParentSide filter needs the POV row (eye-on-other). Join it
        // only when both a POV eye and an active side filter exist.
        [$sideWhere, $sideBind] = $povEye
            ? $this->parentSideFilter($parentSide, 'pov', $povPaternalCluster)
            : ['', []];
        $povJoin = '';
        if ($sideWhere !== '') {
            $povJoin = '
                LEFT JOIN dna_matches2 pov ON pov.sample1 = ? AND pov.sample2 = m.sample2
            ';
            $bind[] = $povEye;
        }

        $bind[] = $sampleId;

        $searchJoin = '';
        $searchWhere = '';
        if ($search !== '') {
            [$lex, $phon] = PhoneticEncoder::buildBoolean($search);
            if ($lex === '' && $phon === '') {
                return 0;
            }
            $lex  = $lex  !== '' ? $lex  : '+__never_matches__';
            $phon = $phon !== '' ? $phon : '+__never_matches__';
            $searchJoin = '
                JOIN dna_samples s ON s.id = m.sample2
                LEFT JOIN people p ON p.dnaSampleId = m.sample2
            ';
            $searchWhere = ' AND (
                MATCH(s.displayName)          AGAINST (? IN BOOLEAN MODE)
             OR MATCH(s.displayName_phonetic) AGAINST (? IN BOOLEAN MODE)
             OR MATCH(p.fullName)             AGAINST (? IN BOOLEAN MODE)
             OR MATCH(p.fullName_phonetic)    AGAINST (? IN BOOLEAN MODE)
            )';
            $bind[] = $lex;
            $bind[] = $phon;
            $bind[] = $lex;
            $bind[] = $phon;
        }

        foreach ($sideBind as $b) {
            $bind[] = $b;
        }

        [$treeWhere, $treeBind] = $this->treeFilter($treeId);
        foreach ($treeBind as $b) {
            $bind[] = $b;
        }

        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_matches2 m
            ' . $eyeJoin . $povJoin . $searchJoin . '
            WHERE m.sample1 = ?' . $searchWhere . $sideWhere . $treeWhere
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

    public function listMatches(int $sampleId, int $page, int $pageSize, ?int $commonWithEye = null, string $search = '', ?int $notesEye = null, ?int $povEye = null, string $parentSide = '', ?string $povPaternalCluster = null, ?int $treeId = null): array
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

        // ParentSide dropdown filter — reuses the pov join above, so it
        // only applies when a POV eye exists (which is also the only
        // case the dropdown is enabled in the UI).
        [$sideWhere, $sideBind] = $povEye
            ? $this->parentSideFilter($parentSide, 'pov', $povPaternalCluster)
            : ['', []];

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
            [$lex, $phon] = PhoneticEncoder::buildBoolean($search);
            if ($lex === '' && $phon === '') {
                return [];
            }
            $lex  = $lex  !== '' ? $lex  : '+__never_matches__';
            $phon = $phon !== '' ? $phon : '+__never_matches__';
            $searchWhere = ' AND (
                MATCH(s.displayName)          AGAINST (? IN BOOLEAN MODE)
             OR MATCH(s.displayName_phonetic) AGAINST (? IN BOOLEAN MODE)
             OR MATCH(p.fullName)             AGAINST (? IN BOOLEAN MODE)
             OR MATCH(p.fullName_phonetic)    AGAINST (? IN BOOLEAN MODE)
            )';
            $bind[] = $lex;
            $bind[] = $phon;
            $bind[] = $lex;
            $bind[] = $phon;
        }

        foreach ($sideBind as $b) {
            $bind[] = $b;
        }

        [$treeWhere, $treeBind] = $this->treeFilter($treeId);
        foreach ($treeBind as $b) {
            $bind[] = $b;
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
              m.assignment,
              m.ignored,
              m.dnapath,
              ' . $noteCol . '
            FROM dna_matches2 m
            ' . $eyeJoin . $povJoin . '
            JOIN dna_samples s ON s.id = m.sample2
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            ' . $notesJoin . '
            WHERE m.sample1 = ?' . $searchWhere . $sideWhere . $treeWhere . '
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
        $this->attachTrees($rows);
        return $rows;
    }

    /**
     * Decorate each match row with the trees its person belongs to,
     * as a `trees` array of {id, name, letter, colour}. Rows with no
     * person, or whose person is in no tree, get [].
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function attachTrees(array &$rows): void
    {
        $personIds = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['person_id'] ?? 0);
            if ($pid > 0) {
                $personIds[$pid] = true;
            }
        }
        $byPerson = $this->treesForPeople(array_keys($personIds));

        foreach ($rows as &$row) {
            $pid = (int) ($row['person_id'] ?? 0);
            $row['trees'] = ($pid > 0 && isset($byPerson[$pid])) ? $byPerson[$pid] : [];
        }
        unset($row);
    }

    /**
     * Trees a single person belongs to, as a list of
     * {id, name, letter, colour}. Empty list if no person / no trees.
     *
     * @return array<int,array<string,mixed>>
     */
    public function treesForPerson(?int $personId): array
    {
        if (! $personId) {
            return [];
        }
        return $this->treesForPeople([$personId])[$personId] ?? [];
    }

    /**
     * One round-trip mapping personId → list of {id,name,letter,colour}
     * for every supplied person. Shared by the match-row decoration and
     * the title-sample lookup.
     *
     * @param array<int> $personIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function treesForPeople(array $personIds): array
    {
        if (! $personIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $links = DB::select("
            SELECT tp.peopleId AS person_id,
                   t.id        AS tree_id,
                   t.name      AS name,
                   t.colour    AS colour
            FROM tree_people tp
            JOIN tree t ON t.id = tp.treeId
            WHERE tp.peopleId IN ({$placeholders})
            ORDER BY t.priority DESC, t.name ASC
        ", array_values($personIds));

        $byPerson = [];
        foreach ($links as $l) {
            $byPerson[(int) $l->person_id][] = [
                'id'     => (int) $l->tree_id,
                'name'   => $l->name,
                'letter' => mb_strtoupper(mb_substr((string) $l->name, 0, 1)),
                'colour' => $l->colour,
            ];
        }
        return $byPerson;
    }
}
