<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'people';
    public $timestamps = false;

    protected $casts = [
        'alt' => 'integer',
        'minBirth' => 'integer',
        'maxBirth' => 'integer',
        'death' => 'integer',
        'father' => 'integer',
        'mother' => 'integer',
        'disable' => 'boolean',
        'treetop' => 'boolean',
        'ddna' => 'boolean',
        'nogedcom' => 'boolean',
    ];

    public function dnaSample()
    {
        return $this->belongsTo(DnaSample::class, 'dnaSampleId');
    }

    public function fatherPerson()
    {
        return $this->belongsTo(self::class, 'father');
    }

    public function motherPerson()
    {
        return $this->belongsTo(self::class, 'mother');
    }
}
