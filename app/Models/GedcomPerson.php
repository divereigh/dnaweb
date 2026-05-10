<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GedcomPerson extends Model
{
    protected $table = 'gedcom_people';
    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'atreeid' => 'integer',
        'ancestryid' => 'integer',
        'peopleid' => 'integer',
        'father' => 'integer',
        'mother' => 'integer',
        'birth' => 'integer',
        'death' => 'integer',
        'dnaMatch' => 'boolean',
        'lostdna' => 'boolean',
    ];
}
