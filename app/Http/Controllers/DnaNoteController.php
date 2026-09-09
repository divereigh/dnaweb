<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DnaNoteController extends Controller
{
    /**
     * Upsert a dna_notes row from the side panel. An empty payload
     * deletes the row (the cleanest way to say "no notes" rather than
     * store an empty string).
     *
     * Notes are local-only as of 2026-09-09. Writes used to set
     * pushreq=1 so worker-ancestry.pl would POST the note back to
     * Ancestry's tags/3 endpoint; that push-back is retired (a number
     * of kits were never set up to receive notes, and we no longer
     * need the round trip), the unit is disabled, and this writes
     * pushreq=0 so nothing is left queued if it is ever re-enabled.
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
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                    notes = VALUES(notes),
                    pushreq = 0
            ', [$sample, $mgmtsample, $notes]);
        }

        return back();
    }
}
