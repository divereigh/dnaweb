<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use App\Services\EyeMatchService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DnaMatchesController extends Controller
{
    public function __construct(
        private DnaSampleService $service,
        private EyeMatchService $eyes,
    ) {}

    public function index(Request $request, int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        // Optional "common with this eye" filter. Validate the eye is a
        // real managed kit and isn't the page's own sample.
        $eyeId = (int) $request->input('eye') ?: null;
        if ($eyeId && ($eyeId === $id || ! $this->eyes->getEye($eyeId))) {
            $eyeId = null;
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
            'eyes' => $this->eyes->listOptions(excludeId: $id, matchesSampleId: $id),
            'eye_id' => $eyeId,
        ]);
    }
}
