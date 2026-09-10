<?php

namespace App\Services;

use App\Support\Format;
use App\Support\PhoneticEncoder;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

class DnaSampleService
{
    public function __construct(
        private KinshipLabelService $kinship,
        private EyeSetService $eyeSet,
        private OriginsService $origins,
    ) {}

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
        $lex = $lex !== '' ? $lex : '+__never_matches__';
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
              s.disabled,
              '.$this->eyeSet->sqlIn('s.id').' AS is_eye,
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
            -- Disabled kits are included deliberately. They stay listed
            -- on the match page of every other sample and their own
            -- matches page still renders, so hiding them here just made
            -- them unfindable by name; the row is badged instead.
            WHERE (
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
            $row['disabled'] = (bool) ($row['disabled'] ?? false);

            return $row;
        }, $rows);
    }

    public function get(int $sampleId): ?array
    {
        $row = DB::selectOne('
            SELECT
              s.id, s.dnaUUID, s.displayName, s.gender, s.createdDate, s.managed, s.disabled,
              '.$this->eyeSet->sqlIn('s.id').' AS is_eye,
              s.photoUrl,
              '.Sql::effectivePaternalCluster('s').',
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
            WHERE s.id = ?
        ', [$sampleId]);

        if (! $row) {
            return null;
        }
        $r = (array) $row;
        // Deliberately NOT filtered on s.disabled. A kit Ancestry has
        // disabled is gone as a *source* of new data, but everything
        // already loaded about it stays valid and stays browsable —
        // filtering here 404'd its matches page while the same sample
        // was still listed on every other sample's page. Callers that
        // queue work check this flag instead; see the disabled
        // short-circuit in loadingStatus() and requeueAll().
        $r['disabled'] = (bool) $r['disabled'];
        // has_session asks "can Ancestry still be reached through this
        // kit" — for a disabled kit the answer is no whatever `managed`
        // says, so the compare links that depend on it stay hidden.
        $r['has_session'] = $r['managed'] !== null && ! $r['disabled'];
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
     * Build the Trees WHERE fragment from include / exclude tree lists.
     * Include = person must be in AT LEAST ONE of the included trees
     * (OR / union). Exclude = person must be in NONE of the excluded
     * trees. Both are self-contained EXISTS keyed on m.sample2 so they
     * work in count and list without a people join.
     *
     * @param  array<int>  $includeIds
     * @param  array<int>  $excludeIds
     * @return array{0:string,1:array} [sqlFragment, binds]
     */
    private function treeFilter(array $includeIds, array $excludeIds): array
    {
        $includeIds = array_values(array_unique(array_filter(array_map('intval', $includeIds))));
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));

        $sql = '';
        $bind = [];

        if ($includeIds) {
            $ph = implode(',', array_fill(0, count($includeIds), '?'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM people pp
                JOIN tree_people tpf ON tpf.peopleId = pp.id
                WHERE pp.dnaSampleId = m.sample2 AND tpf.treeId IN ($ph)
            )";
            foreach ($includeIds as $id) {
                $bind[] = $id;
            }
        }
        if ($excludeIds) {
            $ph = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= " AND NOT EXISTS (
                SELECT 1 FROM people pp
                JOIN tree_people tpf ON tpf.peopleId = pp.id
                WHERE pp.dnaSampleId = m.sample2 AND tpf.treeId IN ($ph)
            )";
            foreach ($excludeIds as $id) {
                $bind[] = $id;
            }
        }

        return [$sql, $bind];
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
            'id' => (int) $r->id,
            'name' => $r->name,
            'letter' => mb_strtoupper(mb_substr((string) $r->name, 0, 1)),
            'colour' => $r->colour,
        ], $rows);
    }

    public function countMatches(int $sampleId, ?int $commonWithEye = null, string $search = '', ?int $povEye = null, string $parentSide = '', ?string $povPaternalCluster = null, array $treeInclude = [], array $treeExclude = []): int
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
            $lex = $lex !== '' ? $lex : '+__never_matches__';
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

        [$treeWhere, $treeBind] = $this->treeFilter($treeInclude, $treeExclude);
        foreach ($treeBind as $b) {
            $bind[] = $b;
        }

        $row = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_matches2 m
            '.$eyeJoin.$povJoin.$searchJoin.'
            WHERE m.sample1 = ?'.$searchWhere.$sideWhere.$treeWhere, $bind);

        return (int) ($row?->c ?? 0);
    }

    /**
     * What is happening to this sample's match-of-match data, and is
     * anything actually working on it?
     *
     * This replaces a boolean loadingInProgress() that asked the wrong
     * question. It read v_pending_match2match, where a *missing* queue
     * row COALESCEs to 'pending' — so it answered "does this sample
     * have any pair that has not been loaded?", which is true of
     * essentially every sample in the database (2.39M of them at the
     * time of writing, against zero actual queue rows). The spinner it
     * drove could therefore never distinguish a queue being drained
     * from one nobody was draining, and sat on "Loading…" forever
     * whenever the workers were stopped.
     *
     * Two changes fix that:
     *
     *   * Read the REAL queue row (LEFT JOIN, l.status IS NULL means
     *     "never queued"), not the view's COALESCEd phantom.
     *   * Ask v_worker_status whether a match2match worker has actually
     *     checked in recently. Outstanding work with no worker is not
     *     loading, it is stuck, and only the heartbeat can tell them
     *     apart — a pending row looks identical either way.
     *
     * Returns a state the page can render directly:
     *
     *   loading    something is running, or queued with a live worker
     *   queued     work outstanding but not progressing — no worker, or
     *              sitting out a retry backoff. `message` says which.
     *   partial    everything settled, but some eyes failed permanently
     *   complete   every eye loaded
     *   unloadable no eye with a live session can see this sample
     *   disabled   Ancestry has disabled the kit; nothing can ever be
     *              fetched about it again
     */
    public function loadingStatus(int $sampleId): array
    {
        // A kit disabled in Ancestry can never be loaded again, from any
        // eye — v_pending_match2match drops it, so the workers will never
        // claim a pair naming it. The counts below would look perfectly
        // healthy (the eyes that matched it are still live kits; it is
        // the *target* that is gone), the never-queued pairs would read
        // as outstanding work, and the page would sit on "Loading…"
        // forever. Answer the real question up front instead.
        $disabled = DB::selectOne('SELECT disabled FROM dna_samples WHERE id = ?', [$sampleId]);
        if ($disabled && (int) $disabled->disabled === 1) {
            return [
                'state' => 'disabled',
                'message' => 'This kit has been disabled in Ancestry, so no more of its data can '
                    .'be fetched. What is shown is whatever had already been loaded, and it may '
                    .'be incomplete.',
                'eyes_total' => 0,
                'eyes_done' => 0,
                'eyes_failed' => 0,
                'outstanding' => 0,
                'running' => 0,
                'worker_alive' => true,
                'worker_seconds_ago' => null,
                'retry_in' => null,
                'reasons' => [],
            ];
        }

        // Every eye that could fetch this sample, with whatever queue
        // row it actually has. Same managed/enabled/session predicate
        // the workers claim on, so "eyes_total" counts only eyes a
        // fetch could really happen through.
        $t = DB::selectOne('
            SELECT COUNT(*)                                          AS eyes_total,
                   SUM(l.status = \'done\')                           AS done,
                   SUM(l.status = \'running\')                        AS running,
                   SUM(l.status = \'pending\')                        AS pending,
                   SUM(l.status = \'abandoned\')                      AS abandoned,
                   SUM(l.status IS NULL)                             AS unqueued,
                   MIN(CASE WHEN l.status = \'pending\'
                             AND l.next_retry_at > NOW()
                            THEN l.next_retry_at END)                AS next_retry_at,
                   SUM(l.status = \'pending\'
                       AND (l.next_retry_at IS NULL
                            OR l.next_retry_at <= NOW()))            AS due_now
            FROM dna_matches2 m
            JOIN dna_samples e ON e.id = m.sample1
                              AND e.disabled = 0
                              AND e.managed IS NOT NULL
            JOIN session s ON s.id = e.managed
            LEFT JOIN dna_match2match_loaded l
                   ON l.mgmtsample = m.sample1 AND l.othsample = m.sample2
            WHERE m.sample2 = ?
        ', [$sampleId]);

        $eyesTotal = (int) ($t?->eyes_total ?? 0);
        $done = (int) ($t?->done ?? 0);
        $running = (int) ($t?->running ?? 0);
        $pending = (int) ($t?->pending ?? 0);
        $abandoned = (int) ($t?->abandoned ?? 0);
        $unqueued = (int) ($t?->unqueued ?? 0);
        $dueNow = (int) ($t?->due_now ?? 0);
        $outstanding = $pending + $unqueued;

        $worker = $this->workerStatus('match2match');
        $workerAlive = $worker['alive'];

        // Why the permanent failures failed. load-dna.pl classifies at
        // the point of failure and the workers store it on the row, so
        // this is the real reason rather than an inference.
        $reasons = [];
        if ($abandoned > 0) {
            foreach (DB::select('
                SELECT COALESCE(last_error_class, \'unknown\') AS class, COUNT(*) AS n
                FROM dna_match2match_loaded
                WHERE othsample = ? AND status = \'abandoned\'
                GROUP BY class
            ', [$sampleId]) as $r) {
                $reasons[(string) $r->class] = (int) $r->n;
            }
        }

        $retryIn = null;
        if ($t?->next_retry_at) {
            $retryIn = max(0, strtotime((string) $t->next_retry_at) - time());
        }

        [$state, $message] = $this->loadingState(
            $eyesTotal, $running, $outstanding, $dueNow, $abandoned,
            $workerAlive, $worker['seconds_ago'], $retryIn, $reasons
        );

        return [
            'state' => $state,
            'message' => $message,
            'eyes_total' => $eyesTotal,
            'eyes_done' => $done,
            'eyes_failed' => $abandoned,
            'outstanding' => $outstanding,
            'running' => $running,
            'worker_alive' => $workerAlive,
            'worker_seconds_ago' => $worker['seconds_ago'],
            'retry_in' => $retryIn,
            'reasons' => $reasons,
        ];
    }

    /**
     * Precedence between the five states, kept in one place so the
     * template never re-derives it. Order matters: "running" beats
     * everything (work is demonstrably happening), and a dead worker
     * beats a retry backoff (the backoff will not be honoured by
     * anything if nothing is looking at the queue).
     */
    private function loadingState(
        int $eyesTotal, int $running, int $outstanding, int $dueNow,
        int $abandoned, bool $workerAlive, ?int $workerSeconds,
        ?int $retryIn, array $reasons
    ): array {
        if ($eyesTotal === 0) {
            return ['unloadable',
                'No managed kit with an active Ancestry session matches this sample, '
                .'so its shared matches cannot be fetched.'];
        }

        if ($running > 0) {
            return ['loading', 'Loading shared matches…'];
        }

        if ($outstanding > 0) {
            if (! $workerAlive) {
                return ['queued', $workerSeconds === null
                    ? 'Queued, but no match2match worker has ever checked in — the loader is not running.'
                    : sprintf('Queued, but no match2match worker has checked in for %s — the loader looks stopped.',
                        self::humanSeconds($workerSeconds))];
            }
            if ($dueNow === 0 && $retryIn !== null) {
                return ['queued', sprintf('Waiting to retry after a failure — next attempt in %s.',
                    self::humanSeconds($retryIn))];
            }

            return ['loading', 'Loading shared matches…'];
        }

        if ($abandoned > 0) {
            return ['partial', self::failureMessage($abandoned, $reasons)];
        }

        return ['complete', ''];
    }

    /**
     * Wording for the permanent failures, from the classes the loader
     * recorded. `gone` is by far the common one and deserves saying
     * plainly: nothing is broken, Ancestry no longer has the data.
     */
    private static function failureMessage(int $abandoned, array $reasons): string
    {
        $eyes = $abandoned === 1 ? '1 eye' : "$abandoned eyes";
        $why = [
            'gone' => 'the match is no longer available on Ancestry (kit deleted or made private)',
            'nosession' => 'that kit\'s Ancestry session has expired',
            'noaccess' => 'that kit cannot see this sample',
            'skip' => 'there was nothing to load',
            'transient' => 'the request kept failing',
        ];

        // One reason is the overwhelmingly common case and reads much
        // better named than enumerated.
        if (count($reasons) === 1) {
            $class = array_key_first($reasons);

            return sprintf('Shared matches could not be loaded through %s: %s.',
                $eyes, $why[$class] ?? 'the load failed');
        }

        $parts = [];
        foreach ($reasons as $class => $n) {
            $parts[] = $n.' — '.($why[$class] ?? 'the load failed');
        }

        return sprintf('Shared matches could not be loaded through %s (%s).',
            $eyes, implode('; ', $parts));
    }

    private static function humanSeconds(int $s): string
    {
        if ($s < 60) {
            return $s.' second'.($s === 1 ? '' : 's');
        }
        $m = (int) round($s / 60);

        return $m.' minute'.($m === 1 ? '' : 's');
    }

    /**
     * Worker liveness, from the heartbeat the Perl workers write on
     * every loop turn including the idle poll (worker_heartbeat /
     * v_worker_status, see heartbeat-schema.sql in the loader repo).
     *
     * The heartbeat is the only thing in the database that can say a
     * worker exists: a queue row being drained and a queue row nobody
     * is draining are otherwise identical. Missing table or missing
     * row is reported as not-alive rather than throwing — the answer
     * "we cannot tell, assume stopped" is the safe direction, and it
     * keeps the page working on a database where the DDL has not been
     * applied yet.
     */
    private function workerStatus(string $worker): array
    {
        try {
            $row = DB::selectOne('
                SELECT alive, seconds_ago FROM v_worker_status WHERE worker = ?
            ', [$worker]);
        } catch (\Throwable $e) {
            return ['alive' => false, 'seconds_ago' => null];
        }

        return [
            'alive' => (int) ($row?->alive ?? 0) > 0,
            'seconds_ago' => $row?->seconds_ago === null ? null : (int) $row->seconds_ago,
        ];
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
        //
        // Deliberately NOT EyeSetService: this asks whether a fetch can
        // still happen, which is the session question. An eye that has
        // lost its session stays visible in the UI but must not have
        // work queued against it.
        //
        // The `oth` join is the same question asked of the other end:
        // a kit Ancestry has disabled can never be re-read either, and
        // v_pending_match2match drops it, so rows revived here would
        // stay pending forever. The page hides RELOAD for such a kit
        // and the controller refuses the POST; this is the backstop.
        return DB::update("
            UPDATE dna_match2match_loaded l
              JOIN dna_samples m ON m.id = l.mgmtsample
                                AND m.disabled = 0
                                AND m.managed IS NOT NULL
              JOIN dna_samples o ON o.id = l.othsample
                                AND o.disabled = 0
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
     *
     * Already a no-op for a kit disabled in Ancestry: the view joins
     * dna_samples on disabled = 0 at both ends, so there is nothing to
     * enqueue. Callers skip it anyway rather than relying on that.
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

        if (! $pairs) {
            return;
        }

        $sql = 'INSERT INTO dna_match2match_loaded
                    (mgmtsample, othsample, status, enqueued_at, priority)
                VALUES '.implode(',', array_fill(0, count($pairs), '(?, ?, ?, NOW(), ?)')).'
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
     * Every match of this sample that is itself an eye, in the same row
     * shape as listMatches() — no pagination. Used to render the "matching
     * eyes" picker at the top of the matches page.
     *
     * Eye-ness here is EyeSetService, not `managed`: a kit whose session has
     * gone still has all its loaded matches, and dropping it from the picker
     * made those unreachable from this page.
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
              '.$this->eyeSet->sqlIn('s.id').' AS other_is_eye,
              s.gender AS other_gender,
              s.createdDate AS other_createdDate,
              s.photoUrl AS other_photoUrl,
              s.userUUID AS other_userUUID,
              '.Sql::effectivePaternalCluster('s').',
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
              AND '.$this->eyeSet->sqlIn('s.id').'
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
        $this->origins->decorateIcons($rows, 'other_id');

        return $rows;
    }

    public function listMatches(int $sampleId, int $page, int $pageSize, ?int $commonWithEye = null, string $search = '', ?int $povEye = null, string $parentSide = '', ?string $povPaternalCluster = null, array $treeInclude = [], array $treeExclude = []): array
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

        // One note per sample since 2026-09-09 (dna_sample_notes), so
        // this no longer depends on which eye is selected — notes used
        // to vanish entirely when the title was not an eye and no eye
        // was picked, because dna_notes had nothing to key on.
        $notesJoin = '
                LEFT JOIN dna_sample_notes n ON n.sample = m.sample2
            ';
        $noteCol = 'n.notes AS note';

        $bind[] = $sampleId;        // m.sample1 = ?

        $searchWhere = '';
        if ($search !== '') {
            [$lex, $phon] = PhoneticEncoder::buildBoolean($search);
            if ($lex === '' && $phon === '') {
                return [];
            }
            $lex = $lex !== '' ? $lex : '+__never_matches__';
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

        [$treeWhere, $treeBind] = $this->treeFilter($treeInclude, $treeExclude);
        foreach ($treeBind as $b) {
            $bind[] = $b;
        }

        $bind[] = $pageSize;
        $bind[] = $offset;

        return $this->selectMatchRows(
            $sampleId,
            $eyeJoin.$povJoin,
            $povCols,
            $notesJoin,
            $noteCol,
            $searchWhere.$sideWhere.$treeWhere,
            'ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC
             LIMIT ? OFFSET ?',
            $bind
        );
    }

    /**
     * Re-read a handful of match rows by their `sample2` ids, decorated
     * exactly as listMatches() decorates them.
     *
     * This is what a write on the matches page reloads instead of the
     * whole `matches` prop: an edit to one person, or to one person's
     * tree membership, changes one row, and re-running the paged query
     * to pick up that one row throws away the list the user is looking
     * at. Deliberately ignores the search / ParentSide / tree filters —
     * the caller is asking about rows it already has on screen, and a
     * row that has just drifted out of the active filter should still
     * come back with its new values rather than silently vanishing
     * mid-edit.
     *
     * @param  array<int,int>  $otherIds
     * @return array<int,array<string,mixed>>
     */
    public function matchRows(int $sampleId, array $otherIds, ?int $commonWithEye = null, ?int $povEye = null): array
    {
        $otherIds = array_values(array_unique(array_filter(array_map('intval', $otherIds))));
        if (! $otherIds) {
            return [];
        }

        $bind = [];

        $eyeJoin = '';
        if ($commonWithEye) {
            $eyeJoin = '
                JOIN dna_matches2 eyem ON eyem.sample1 = ? AND eyem.sample2 = m.sample2
            ';
            $bind[] = $commonWithEye;
        }

        $povJoin = '';
        $povCols = 'NULL AS matchClusterCode, NULL AS parentSide';
        if ($povEye) {
            $povJoin = '
                LEFT JOIN dna_matches2 pov ON pov.sample1 = ? AND pov.sample2 = m.sample2
            ';
            $povCols = 'pov.matchClusterCode AS matchClusterCode, pov.parentSide AS parentSide';
            $bind[] = $povEye;
        }

        $notesJoin = '
                LEFT JOIN dna_sample_notes n ON n.sample = m.sample2
            ';

        $bind[] = $sampleId;        // m.sample1 = ?

        // Inlined rather than bound: this list is short (one row, or one
        // person's worth) and already integer-cast above.
        $in = ' AND m.sample2 IN ('.implode(',', $otherIds).')';

        return $this->selectMatchRows(
            $sampleId,
            $eyeJoin.$povJoin,
            $povCols,
            $notesJoin,
            'n.notes AS note',
            $in,
            'ORDER BY m.sharedCentimorgans DESC, m.sample2 ASC',
            $bind
        );
    }

    /**
     * The match-row SELECT and its PHP-side decoration, shared by
     * listMatches() (paged) and matchRows() (by id). Callers assemble
     * their own joins, WHERE tail and ORDER/LIMIT tail, and pass binds
     * in the order those fragments appear.
     *
     * @param  array<int,mixed>  $bind
     * @return array<int,array<string,mixed>>
     */
    private function selectMatchRows(
        int $sampleId,
        string $joins,
        string $povCols,
        string $notesJoin,
        string $noteCol,
        string $whereTail,
        string $orderTail,
        array $bind,
    ): array {
        $rows = DB::select('
            SELECT
              m.sample2 AS other_id,
              s.dnaUUID AS other_uuid,
              s.displayName AS other_name,
              s.managed AS other_managed,
              '.$this->eyeSet->sqlIn('s.id').' AS other_is_eye,
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
              '.$povCols.',
              m.predictedKinships,
              m.assignment,
              m.ignored,
              m.dnapath,
              '.$noteCol.'
            FROM dna_matches2 m
            '.$joins.'
            JOIN dna_samples s ON s.id = m.sample2
            LEFT JOIN people p ON p.dnaSampleId = m.sample2
            LEFT JOIN dna_samples admin ON admin.id = s.adminid
            '.$notesJoin.'
            WHERE m.sample1 = ?'.$whereTail.'
            '.$orderTail.'
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
        $this->origins->decorateIcons($rows, 'other_id');

        return $rows;
    }

    /**
     * Decorate each match row with the trees its person belongs to,
     * as a `trees` array of {id, name, letter, colour}. Rows with no
     * person, or whose person is in no tree, get [].
     *
     * @param  array<int,array<string,mixed>>  $rows
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
     * @param  array<int>  $personIds
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
                'id' => (int) $l->tree_id,
                'name' => $l->name,
                'letter' => mb_strtoupper(mb_substr((string) $l->name, 0, 1)),
                'colour' => $l->colour,
            ];
        }

        return $byPerson;
    }
}
