<?php

namespace App\Http\Controllers;

use App\Services\CommonMatchService;
use App\Services\EyeMatchService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CommonMatchesController extends Controller
{
    public function __construct(
        private CommonMatchService $service,
        private EyeMatchService $eyes,
    ) {}

    public function index(Request $request, int $id, int $otherId)
    {
        $eye = $this->eyes->getEye($id);
        abort_unless($eye, 404, 'Eye not found');

        $match = $this->eyes->getMatchSummary($id, $otherId);
        abort_unless($match, 404, 'Match not found');

        $perPage = (int) ($request->input('per_page') ?: 50);
        if (!in_array($perPage, CommonMatchService::ALLOWED_PER_PAGE, true)) {
            $perPage = 50;
        }

        $page = max((int) ($request->input('page') ?: 1), 1);
        $total = $this->service->count($id, $otherId);
        $pages = $total ? (int) ceil($total / $perPage) : 1;
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        return Inertia::render('Eyes/Common', [
            'eye' => $eye,
            'match' => $match,
            'common' => $this->service->list($id, $otherId, $perPage, $offset),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'allowed_per_page' => CommonMatchService::ALLOWED_PER_PAGE,
        ]);
    }
}
