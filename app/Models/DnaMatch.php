<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnaMatch extends Model
{
    protected $table = 'dna_matches2';
    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'sample1' => 'integer',
        'sample2' => 'integer',
        'sharedCentimorgans' => 'integer',
        'numSharedSegments' => 'integer',
        'meiosis' => 'integer',
        'ignored' => 'boolean',
    ];
}
