<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DnaNoteController extends Controller
{
    /**
     * Upsert the note for one DNA sample. An empty payload deletes the
     * row (the cleanest way to say "no notes" rather than store an
     * empty string).
     *
     * Notes live in dna_sample_notes, one row per sample, as of
     * 2026-09-09. They used to live in dna_notes keyed
     * (sample, mgmtsample) because that is how Ancestry stores tag 3 —
     * a note belonged to the eye that wrote it, so the same person
     * could carry a different note through every kit you looked from,
     * and the UI had to pick a "notes eye" before it could show or
     * edit one at all. deploy/dna-sample-notes.sql creates the new
     * table and folds the old rows into it.
     *
     * dna_notes is left frozen: load-dna.pl no longer fills it, the
     * push-back worker that drained pushreq was retired 2026-09-09,
     * and it stays as the record of what came from Ancestry.
     */
    public function update(Request $request, int $sample)
    {
        $data = $request->validate([
            // TEXT column. The old varchar(1000) would have truncated
            // the merged multi-eye notes, the longest of which is 3,354
            // characters, on their first edit.
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $notes = trim((string) ($data['notes'] ?? ''));

        if ($notes === '') {
            DB::delete('DELETE FROM dna_sample_notes WHERE sample = ?', [$sample]);
        } else {
            DB::statement('
                INSERT INTO dna_sample_notes (sample, notes)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE notes = VALUES(notes)
            ', [$sample, $notes]);
        }

        return back();
    }
}
