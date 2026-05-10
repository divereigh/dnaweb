<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'people';
    public $timestamps = false;

    // Columns the web app is allowed to write. Loader-managed columns
    // (treetop, ddna, nogedcom, father, mother, alt) are deliberately
    // excluded for now — Phase 5 will broaden this.
    protected $fillable = [
        'fullName',
        'dnaSampleId',
        'gender',
        'minBirth',
        'maxBirth',
        'death',
        'notes',
    ];

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
