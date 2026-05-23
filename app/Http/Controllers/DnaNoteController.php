<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DnaNoteController extends Controller
{
    /**
     * Upsert a dna_notes row from the side panel. pushreq=1 so the
     * Perl loader knows to push the change back to Ancestry on its
     * next pass. An empty payload deletes the row (the cleanest way
     * to say "no notes" rather than store an empty string).
     */
    public function update(Request $request, int $sample, int $mgmtsample)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = trim((string) ($data['notes'] ?? ''));

        if ($notes === '') {
            DB::delete(
                'DELETE FROM dna_notes WHERE sample = ? AND mgmtsample = ?',
                [$sample, $mgmtsample],
            );
        } else {
            DB::statement('
                INSERT INTO dna_notes (sample, mgmtsample, notes, pushreq)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    notes = VALUES(notes),
                    pushreq = 1
            ', [$sample, $mgmtsample, $notes]);
        }

        return back();
    }
}
