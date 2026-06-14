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
     * Add a person to a tree group from the matches page. The tree is
     * either an existing one (tree_id) or a brand-new one named by
     * tree_name — in which case it's find-or-created by name so we
     * never end up with duplicate tree rows. Writes a tree_people row
     * with dna=1 (these are always DNA-match people); an optional
     * colour repaints the tree so the freshly-added pill shows it.
     */
    public function addPerson(Request $request)
    {
        $data = $request->validate([
            'tree_id'   => ['nullable', 'integer'],
            'tree_name' => ['nullable', 'string', 'max:100'],
            'person_id' => ['required', 'integer'],
            'colour'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        abort_unless(
            DB::selectOne('SELECT id FROM people WHERE id = ?', [$data['person_id']]),
            404,
            'Person not found'
        );

        $treeId = $data['tree_id'] ?? null;
        if ($treeId) {
            abort_unless(
                DB::selectOne('SELECT id FROM tree WHERE id = ?', [$treeId]),
                404,
                'Tree not found'
            );
        } else {
            $name = trim((string) ($data['tree_name'] ?? ''));
            abort_if($name === '', 422, 'A tree id or name is required');
            // Find-or-create by name so retyping an existing tree's
            // name reuses it rather than spawning a duplicate.
            $existing = DB::selectOne('SELECT id FROM tree WHERE name = ?', [$name]);
            if ($existing) {
                $treeId = (int) $existing->id;
            } else {
                DB::insert('INSERT INTO tree (name, description, priority) VALUES (?, \'\', 0)', [$name]);
                $treeId = (int) DB::getPdo()->lastInsertId();
            }
        }

        // disable=1 on a fresh add — a hand-added member starts disabled
        // (per-tree). On a re-add (ON DUPLICATE) leave disable untouched
        // so we don't re-disable a member that was enabled.
        DB::statement('
            INSERT INTO tree_people (treeId, peopleId, dna, disable)
            VALUES (?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE dna = 1
        ', [$treeId, $data['person_id']]);

        if (! empty($data['colour'])) {
            DB::update('UPDATE tree SET colour = ? WHERE id = ?', [$data['colour'], $treeId]);
        }

        return back();
    }

    /**
     * Remove a person from a tree group — deletes the tree_people row.
     * The tree itself is left intact even if it ends up empty (the
     * Perl loaders own tree lifecycle).
     */
    public function removePerson(Request $request)
    {
        $data = $request->validate([
            'tree_id'   => ['required', 'integer'],
            'person_id' => ['required', 'integer'],
        ]);

        DB::delete(
            'DELETE FROM tree_people WHERE treeId = ? AND peopleId = ?',
            [$data['tree_id'], $data['person_id']]
        );

        return back();
    }
}
