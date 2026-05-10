<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DnaMatchesController extends Controller
{
    public function __construct(private DnaSampleService $service) {}

    public function index(Request $request, int $id)
    {
        $sample = $this->service->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        $page = max((int) ($request->input('page') ?: 1), 1);
        $pageSize = 50;

        $total = $this->service->countMatches($id);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return Inertia::render('Dna/Matches', [
            'sample' => $sample,
            'matches' => $this->service->listMatches($id, $page, $pageSize),
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'per_page' => $pageSize,
        ]);
    }
}
