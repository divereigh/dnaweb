<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use App\Services\EyeMatchService;
use App\Services\OriginsService;
use App\Services\PersonDetailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DnaMatchesController extends Controller
{
    public function __construct(
        private DnaSampleService $service,
        private EyeMatchService $eyes,
        private PersonDetailService $persons,
        private OriginsService $origins,
    ) {}

    /**
     * Force a full reload: flip every queue row for this sample back
     * to pending with progress cleared, plus enqueue any pending
     * pairs that don't have a row yet. The page polling picks up the
     * fresh `loading_status` state and the spinner takes
     * over until the workers drain.
     */
    public function requeue(int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');
        // Disabled in Ancestry: the kit is gone as a source, so there is
        // nothing to re-fetch and the queue rows would never be claimed.
        // The button is hidden client-side; refuse here too so a stale
        // page (or a hand-rolled POST) can't put work on the queue.
        abort_if($sample['disabled'], 403, 'This kit is disabled in Ancestry; nothing more can be loaded.');
        $this->service->requeueAll($id);
        $this->service->enqueueForSample($id);

        return back();
    }

    public function index(Request $request, int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        // Give the title sample the same origin_icons the match rows
        // get. Done here rather than in DnaSampleService::get() because
        // every other caller of get() — including this controller's own
        // requeue() — only wants the existence check, and this is a
        // whole extra query.
        $sampleRows = [$sample];
        $this->origins->decorateIcons($sampleRows, 'id');
        $sample = $sampleRows[0];

        // Skip the enqueue + every expensive query on partial reloads
        // (loading-poll, search-debounce, etc). The Vue side already
        // tells Inertia which props it wants via `only:`; closures
        // below are evaluated lazily, so a poll that asks only for
        // `loading_status` doesn't re-fetch matches/eye_matches.
        $isPartial = $request->header('X-Inertia-Partial-Data') !== null;

        if (! $isPartial && ! $sample['disabled']) {
            // Visiting this page is what tells the queue "someone cares
            // about this sample". Idempotent at priority=10 — but doing
            // the v_pending_match2match scan once per poll was burning
            // ~350ms of DB work per 10s tick.
            //
            // Skipped entirely for a kit Ancestry has disabled: it can
            // never be loaded again, so the page is read-only history
            // and the scan would find nothing anyway.
            $this->service->enqueueForSample($id);
        }

        $eyeId = (int) $request->input('eye') ?: null;
        $selectedEye = null;
        if ($eyeId === $id) {
            $eyeId = null;
        }
        if ($eyeId) {
            $selectedEye = $this->eyes->getEye($eyeId);
            if (! $selectedEye) {
                $eyeId = null;
            }
        }

        $page = max((int) ($request->input('page') ?: 1), 1);
        $pageSize = 50;
        $search = trim((string) $request->input('q', ''));

        // ParentSide dropdown: ALL / PATERNAL / MATERNAL / P1 / P2.
        // Only meaningful when there's a POV eye (selected eye, or the
        // title is itself an eye) — otherwise there's no per-row side
        // data, so we force it back to ALL below once $povEye is known.
        $side = strtoupper(trim((string) $request->input('side', '')));
        if (! in_array($side, ['PATERNAL', 'MATERNAL', 'P1', 'P2'], true)) {
            $side = 'ALL';
        }

        // Trees filter: include = person must be in ANY of these trees;
        // exclude = person must be in NONE of these trees.
        $treeInclude = array_values(array_filter(array_map('intval', (array) $request->input('tin', []))));
        $treeExclude = array_values(array_filter(array_map('intval', (array) $request->input('tex', []))));

        // Whose ParentSide do we show next to each match row? The
        // selected eye wins — the user is explicitly looking through
        // that eye, so its cluster is the relevant one. If no eye is
        // selected but the title is itself a managed eye, fall back to
        // the title's own. Else no cluster.
        //
        // Notes used to hang off this same choice, which is why they
        // disappeared entirely on a non-eye sample with no eye picked.
        // Since 2026-09-09 there is one note per sample
        // (dna_sample_notes) and no eye is involved.
        $povEye = $eyeId ?: (! empty($sample['managed']) ? $id : null);
        // No POV eye → no per-row side data → the dropdown is disabled
        // client-side; mirror that here so a stale ?side= in the URL
        // can't silently filter to an empty list.
        if (! $povEye) {
            $side = 'ALL';
        }
        // ParentSide pill data. Per-row cluster needs the POV eye's
        // paternalCluster to flip p1/p2 into PATERNAL/MATERNAL (only
        // used when the row's `parentSide` enum is NULL — non-null
        // wins). The title-level pill shows up when an eye is
        // selected, displaying the title's ParentSide *from that
        // eye's POV*. dna_matches2 (sample1=eye, sample2=title) is
        // the row that carries it.
        $povPaternalCluster = null;
        if ($povEye === $id) {
            $povPaternalCluster = $sample['paternalCluster'] ?? null;
        } elseif ($povEye && $selectedEye) {
            $povPaternalCluster = $selectedEye['paternalCluster'] ?? null;
        }

        $titlePill = null;
        if ($eyeId && $selectedEye) {
            $row = DB::selectOne(
                'SELECT matchClusterCode, parentSide
                   FROM dna_matches2
                  WHERE sample1 = ? AND sample2 = ?',
                [$eyeId, $id]
            );
            if ($row && ($row->matchClusterCode || $row->parentSide)) {
                $titlePill = [
                    'matchClusterCode' => $row->matchClusterCode,
                    'parentSide' => $row->parentSide,
                    'paternalCluster' => $selectedEye['paternalCluster'] ?? null,
                ];
            }
        }

        // Cache the count per-request — `total`, `pages` and the
        // page-clamp in `matches` would otherwise call countMatches
        // three times.
        $countMemo = null;
        $count = function () use (&$countMemo, $id, $eyeId, $search, $povEye, $side, $povPaternalCluster, $treeInclude, $treeExclude) {
            return $countMemo ??= $this->service->countMatches($id, $eyeId, $search, $povEye, $side, $povPaternalCluster, $treeInclude, $treeExclude);
        };
        $resolvePage = function () use ($count, $page, $pageSize) {
            return min($page, max(1, (int) ceil($count() / $pageSize)));
        };

        // Set of people.id connected to the title's person via shared
        // ancestry (walk-both). Materialised once on demand; used to
        // annotate each match row with `connected_via_tree` so the
        // map doesn't have to ship over the wire (can be 30k+ ids).
        $connectedMemo = null;
        $connected = function () use (&$connectedMemo, $sample) {
            if ($connectedMemo !== null) {
                return $connectedMemo;
            }
            if (empty($sample['person_id'])) {
                return $connectedMemo = [];
            }

            return $connectedMemo = $this->persons->connectedPeopleSet((int) $sample['person_id']);
        };
        $annotateConnected = function (array $rows) use ($connected) {
            $set = $connected();
            foreach ($rows as &$row) {
                $pid = (int) ($row['person_id'] ?? 0);
                $row['connected_via_tree'] = $pid > 0 && isset($set[$pid]);
            }

            return $rows;
        };

        // The title sample's own note. One row per sample, so this is
        // the same note every other page shows for it.
        $titleNote = fn () => optional(DB::selectOne(
            'SELECT notes FROM dna_sample_notes WHERE sample = ?',
            [$id]
        ))->notes;

        return Inertia::render('Dna/Matches', [
            'sample' => $sample,
            'eye_id' => $eyeId,
            'selected_eye' => $selectedEye,
            'per_page' => $pageSize,
            'filters' => ['q' => $search, 'side' => $side, 'tin' => $treeInclude, 'tex' => $treeExclude],
            'side_enabled' => (bool) $povEye,
            'tree_options' => fn () => $this->service->treeOptionsForSample($id),
            'title_note' => $titleNote,
            'pov_paternal_cluster' => $povPaternalCluster,
            'title_pill' => $titlePill,
            'title_trees' => fn () => $this->service->treesForPerson(
                $sample['person_id'] ? (int) $sample['person_id'] : null
            ),

            // Heavy props as closures — Inertia only invokes them
            // when the response includes the corresponding key, so
            // a poll for `loading_status` doesn't re-fetch
            // matches / eye_matches / etc.
            'matches' => fn () => $annotateConnected(
                $this->service->listMatches($id, $resolvePage(), $pageSize, $eyeId, $search, $povEye, $side, $povPaternalCluster, $treeInclude, $treeExclude)
            ),
            'total' => fn () => $count(),
            'pages' => fn () => max(1, (int) ceil($count() / $pageSize)),
            'page' => fn () => $resolvePage(),
            'eye_matches' => fn () => $annotateConnected($this->service->listEyeMatches($id)),
            'loading_status' => fn () => $this->service->loadingStatus($id),
            'ancestry_trees' => fn () => $sample['person_id']
                ? $this->persons->ancestryTrees((int) $sample['person_id'])
                : [],
        ]);
    }
}
