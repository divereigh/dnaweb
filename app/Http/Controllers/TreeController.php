<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TreeController extends Controller
{
    /**
     * Update a tree's name and pill colour from the side panel.
     * Colour is a #rrggbb hex string or null (null = white pill).
     * The `tree` table is otherwise owned by the Perl loaders; name
     * and colour are the only columns the web app writes.
     */
    public function update(Request $request, int $tree)
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:100'],
            'colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        abort_unless(
            DB::selectOne('SELECT id FROM tree WHERE id = ?', [$tree]),
            404,
            'Tree not found'
        );

        DB::update(
            'UPDATE tree SET name = ?, colour = ? WHERE id = ?',
            [trim($data['name']), $data['colour'] ?? null, $tree]
        );

        return back();
    }

    /**
     * Add a person to an existing tree group from the matches page.
     *
     * Writes a tree_people row with dna=1 (these are always DNA-match
     * people). An optional colour updates the tree so the freshly-added
     * pill shows in the chosen colour.
     */
    public function addPerson(Request $request)
    {
        $data = $request->validate([
            'tree_id'   => ['required', 'integer'],
            'person_id' => ['required', 'integer'],
            'colour'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        abort_unless(
            DB::selectOne('SELECT id FROM tree WHERE id = ?', [$data['tree_id']]),
            404,
            'Tree not found'
        );
        abort_unless(
            DB::selectOne('SELECT id FROM people WHERE id = ?', [$data['person_id']]),
            404,
            'Person not found'
        );

        DB::statement('
            INSERT INTO tree_people (treeId, peopleId, dna)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE dna = 1
        ', [$data['tree_id'], $data['person_id']]);

        if (! empty($data['colour'])) {
            DB::update('UPDATE tree SET colour = ? WHERE id = ?', [$data['colour'], $data['tree_id']]);
        }

        return back();
    }
}
