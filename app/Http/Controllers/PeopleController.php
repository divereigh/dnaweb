<?php

namespace App\Http\Controllers;

use App\Services\PeopleSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PeopleController extends Controller
{
    public function __construct(private PeopleSearchService $service) {}

    public function index(Request $request)
    {
        $q = (string) $request->input('q', '');
        $linked = $request->boolean('linked') ? 1 : 0;
        $hasMatches = $request->boolean('has_matches') ? 1 : 0;
        $sort = (string) ($request->input('sort') ?: 'name');
        $page = max((int) ($request->input('page') ?: 1), 1);
        $pageSize = 50;

        $total = $this->service->count($q, $linked, $hasMatches);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return Inertia::render('People/Index', [
            'people' => $this->service->list($q, $linked, $hasMatches, $sort, $page, $pageSize),
            'filters' => [
                'q' => $q,
                'linked' => (bool) $linked,
                'has_matches' => (bool) $hasMatches,
            ],
            'sort' => $sort,
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'per_page' => $pageSize,
        ]);
    }
}
