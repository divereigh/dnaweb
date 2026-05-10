<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DnaController extends Controller
{
    public function __construct(private DnaSampleService $service) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $page = max((int) ($request->input('page') ?: 1), 1);
        $pageSize = 50;
        $offset = ($page - 1) * $pageSize;

        $samples = [];
        $hasNext = false;
        if ($q !== '') {
            $rows = $this->service->search($q, $pageSize + 1, $offset);
            $hasNext = count($rows) > $pageSize;
            $samples = array_slice($rows, 0, $pageSize);
        }

        return Inertia::render('Dna/Index', [
            'samples' => $samples,
            'q' => $q,
            'page' => $page,
            'has_next' => $hasNext,
        ]);
    }
}
