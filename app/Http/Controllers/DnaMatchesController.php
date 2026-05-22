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

        // Cache the count per-request — `total`, `pages` and the
        // page-clamp in `matches` would otherwise call countMatches
        // three times.
        $countMemo = null;
        $count = function () use (&$countMemo, $id, $eyeId, $search) {
            return $countMemo ??= $this->service->countMatches($id, $eyeId, $search);
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

        return Inertia::render('Dna/Matches', [
            'sample'         => $sample,
            'eye_id'         => $eyeId,
            'selected_eye'   => $selectedEye,
            'per_page'       => $pageSize,
            'filters'        => ['q' => $search],

            // Heavy props as closures — Inertia only invokes them
            // when the response includes the corresponding key, so
            // a poll for `loading_in_progress` doesn't re-fetch
            // matches / eye_matches / etc.
            'matches'             => fn () => $annotateConnected(
                $this->service->listMatches($id, $resolvePage(), $pageSize, $eyeId, $search)
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
