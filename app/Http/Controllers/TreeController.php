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
}
