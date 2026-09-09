<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The old per-eye notes table, frozen 2026-09-09: notes moved to
 * dna_sample_notes (one row per sample) and load-dna.pl no longer
 * fills this one. Kept as the record of what came from Ancestry and as
 * the rollback path for deploy/dna-sample-notes.sql. Nothing in the app
 * reads it — new code wants dna_sample_notes.
 */
class DnaNote extends Model
{
    protected $table = 'dna_notes';

    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $casts = [
        'sample' => 'integer',
        'mgmtsample' => 'integer',
        'loaded' => 'datetime',
    ];
}
