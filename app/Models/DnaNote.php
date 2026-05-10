<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
