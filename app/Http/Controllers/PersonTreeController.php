<?php

namespace App\Http\Controllers;

use App\Services\FamilyTreeService;
use App\Services\PersonDetailService;
use Illuminate\Http\Request;
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

    /**
     * One step further out from a card the page already holds. Plain JSON,
     * not an Inertia response: the tree merges the people into the chart it
     * has already drawn rather than re-rendering the page, so that expanding
     * a branch never moves what is on screen.
     */
    public function expand(Request $request, int $id)
    {
        $validated = $request->validate([
            'rel' => 'required|in:parents,children,siblings',
            'levels' => 'sometimes|integer|min:1|max:'.FamilyTreeService::MAX_EXPAND_LEVELS,
        ]);

        return response()->json($this->tree->expand(
            $id,
            $validated['rel'],
            (int) ($validated['levels'] ?? 1),
        ));
    }
}
