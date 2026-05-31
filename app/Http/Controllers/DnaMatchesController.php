<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use App\Services\EyeMatchService;
use App\Services\PersonDetailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DnaMatchesController extends Controller
{
    public function __construct(
        private DnaSampleService $service,
        private EyeMatchService $eyes,
        private PersonDetailService $persons,
    ) {}

    /**
     * Force a full reload: flip every queue row for this sample back
     * to pending with progress cleared, plus enqueue any pending
     * pairs that don't have a row yet. The page polling picks up the
     * fresh `loading_in_progress=true` state and the spinner takes
     * over until the workers drain.
     */
    public function requeue(int $id)
    {
        abort_unless($this->service->get($id), 404, 'DNA sample not found');
        $this->service->requeueAll($id);
        $this->service->enqueueForSample($id);
        return back();
    }

    public function index(Request $request, int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        // Skip the enqueue + every expensive query on partial reloads
        // (loading-poll, search-debounce, etc). The Vue side already
        // tells Inertia which props it wants via `only:`; closures
        // below are evaluated lazily, so a poll that asks only for
        // `loading_in_progress` doesn't re-fetch matches/eye_matches.
        $isPartial = $request->header('X-Inertia-Partial-Data') !== null;

        if (!$isPartial) {
            // Visiting this page is what tells the queue "someone cares
            // about this sample". Idempotent at priority=10 — but doing
            // the v_pending_match2match scan once per poll was burning
            // ~350ms of DB work per 10s tick.
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

        // Whose notes / ParentSide do we show next to each match row?
        // The selected eye wins — the user is explicitly looking
        // through that eye, so its notes (and ParentSide cluster) are
        // the relevant ones. If no eye is selected but the title is
        // itself a managed eye, fall back to the title's own. Else
        // no notes / cluster. Notes and ParentSide use the same
        // POV eye (one is a row-level note, the other a row-level
        // cluster, both keyed off the same "who's looking" question).
        $notesEye = $eyeId ?: (!empty($sample['managed']) ? $id : null);
        $povEye = $notesEye;
        // No POV eye → no per-row side data → the dropdown is disabled
        // client-side; mirror that here so a stale ?side= in the URL
        // can't silently filter to an empty list.
        if (! $povEye) {
            $side = 'ALL';
        }
        $notesEyeLabel = null;
        if ($notesEye === $id) {
            $notesEyeLabel = $sample['display_label'];
        } elseif ($notesEye && $selectedEye) {
            $notesEyeLabel = $selectedEye['display_label'];
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
            $row = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT matchClusterCode, parentSide
                   FROM dna_matches2
                  WHERE sample1 = ? AND sample2 = ?',
                [$eyeId, $id]
            );
            if ($row && ($row->matchClusterCode || $row->parentSide)) {
                $titlePill = [
                    'matchClusterCode' => $row->matchClusterCode,
                    'parentSide'       => $row->parentSide,
                    'paternalCluster'  => $selectedEye['paternalCluster'] ?? null,
                ];
            }
        }

        // Cache the count per-request — `total`, `pages` and the
        // page-clamp in `matches` would otherwise call countMatches
        // three times.
        $countMemo = null;
        $count = function () use (&$countMemo, $id, $eyeId, $search, $povEye, $side, $povPaternalCluster) {
            return $countMemo ??= $this->service->countMatches($id, $eyeId, $search, $povEye, $side, $povPaternalCluster);
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

        // The title sample's own note, viewed through whichever eye
        // we're using for the rest of the notes ($notesEye). When
        // $notesEye is null (no managed-eye context at all) there's
        // no place for a note to live, so skip the lookup. When the
        // title is itself an eye with no selected filter, $notesEye
        // === $id, and the lookup just finds nothing in normal use
        // (notes about oneself aren't usually written) — harmless.
        $titleNote = fn () => $notesEye
            ? optional(\Illuminate\Support\Facades\DB::selectOne(
                'SELECT notes FROM dna_notes WHERE sample = ? AND mgmtsample = ?',
                [$id, $notesEye]
            ))->notes
            : null;

        return Inertia::render('Dna/Matches', [
            'sample'               => $sample,
            'eye_id'               => $eyeId,
            'selected_eye'         => $selectedEye,
            'per_page'             => $pageSize,
            'filters'              => ['q' => $search, 'side' => $side],
            'side_enabled'         => (bool) $povEye,
            'title_note'           => $titleNote,
            'notes_eye_id'         => $notesEye,
            'notes_eye_label'      => $notesEyeLabel,
            'pov_paternal_cluster' => $povPaternalCluster,
            'title_pill'           => $titlePill,

            // Heavy props as closures — Inertia only invokes them
            // when the response includes the corresponding key, so
            // a poll for `loading_in_progress` doesn't re-fetch
            // matches / eye_matches / etc.
            'matches'             => fn () => $annotateConnected(
                $this->service->listMatches($id, $resolvePage(), $pageSize, $eyeId, $search, $notesEye, $povEye, $side, $povPaternalCluster)
            ),
            'total'               => fn () => $count(),
            'pages'               => fn () => max(1, (int) ceil($count() / $pageSize)),
            'page'                => fn () => $resolvePage(),
            'eye_matches'         => fn () => $annotateConnected($this->service->listEyeMatches($id)),
            'loading_in_progress' => fn () => $this->service->loadingInProgress($id),
            'ancestry_trees'      => fn () => $sample['person_id']
                ? $this->persons->ancestryTrees((int) $sample['person_id'])
                : [],
        ]);
    }
}
