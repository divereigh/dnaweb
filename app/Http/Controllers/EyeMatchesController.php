<?php

namespace App\Http\Controllers;

use App\Services\EyeMatchService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EyeMatchesController extends Controller
{
    public function __construct(private EyeMatchService $service) {}

    public function index(Request $request, int $id)
    {
        $eye = $this->service->getEye($id);
        abort_unless($eye, 404, 'Eye not found');

        $perPage = (int) ($request->input('per_page') ?: 50);
        if (!in_array($perPage, EyeMatchService::ALLOWED_PER_PAGE, true)) {
            $perPage = 50;
        }

        $page = max((int) ($request->input('page') ?: 1), 1);
        $sort = (string) ($request->input('sort') ?: 'cm');
        $direction = (string) ($request->input('dir') ?: 'desc');

        $search = (string) $request->input('q', '');
        $hasNotes = $request->boolean('has_notes') ? 1 : 0;
        $hideIgnored = $request->boolean('hide_ignored') ? 1 : 0;
        $onlyEyes = $request->boolean('only_eyes') ? 1 : 0;
        $cluster = (string) $request->input('cluster', '');

        $total = $this->service->countMatches($id, $search, $hasNotes, $hideIgnored, $onlyEyes, $cluster);
        $pages = $total ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $matches = $this->service->listMatches(
            $id, $search, $hasNotes, $hideIgnored, $onlyEyes, $cluster,
            $sort, $direction, $perPage, $offset,
        );

        return Inertia::render('Eyes/Matches', [
            'eye' => $eye,
            'matches' => $matches,
            'clusters' => $this->service->listClusters($id),
            'filters' => [
                'q' => $search,
                'has_notes' => (bool) $hasNotes,
                'hide_ignored' => (bool) $hideIgnored,
                'only_eyes' => (bool) $onlyEyes,
                'cluster' => $cluster,
            ],
            'sort' => $sort,
            'dir' => $direction,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'allowed_per_page' => EyeMatchService::ALLOWED_PER_PAGE,
        ]);
    }
}
