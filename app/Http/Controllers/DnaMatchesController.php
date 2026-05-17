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

    public function index(Request $request, int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        // Visiting this page is what tells the queue "someone cares
        // about this sample". Idempotent at priority=10 so repeated
        // visits don't pile up duplicates but a busy sample bumps
        // priority over routine background fills.
        $this->service->enqueueForSample($id);

        // Optional "common with this eye" filter. Validate the eye is a
        // real managed kit and isn't the page's own sample.
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

        $total = $this->service->countMatches($id, $eyeId);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return Inertia::render('Dna/Matches', [
            'sample' => $sample,
            'matches' => $this->service->listMatches($id, $page, $pageSize, $eyeId),
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'per_page' => $pageSize,
            'eye_matches' => $this->service->listEyeMatches($id),
            'eye_id' => $eyeId,
            'selected_eye' => $selectedEye,
            'loading_in_progress' => $this->service->loadingInProgress($id),
            'ancestry_trees' => $sample['person_id']
                ? $this->persons->ancestryTrees((int) $sample['person_id'])
                : [],
        ]);
    }
}
