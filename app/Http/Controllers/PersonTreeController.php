<?php

namespace App\Http\Controllers;

use App\Services\FamilyTreeService;
use App\Services\PersonDetailService;
use Inertia\Inertia;

class PersonTreeController extends Controller
{
    public function __construct(
        private FamilyTreeService $tree,
        private PersonDetailService $detail,
    ) {}

    public function show(int $id)
    {
        $tree = $this->tree->build($id);
        abort_unless($tree, 404, 'Person not found');

        $person = $this->detail->get($id);

        return Inertia::render('People/Tree', [
            'person' => $person,
            'tree' => $tree,
        ]);
    }
}
