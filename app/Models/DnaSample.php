<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnaSample extends Model
{
    protected $table = 'dna_samples';
    public $timestamps = false;

    protected $casts = [
        'createdDate' => 'integer',
        'managed' => 'integer',
        'disabled' => 'boolean',
    ];

    public function person()
    {
        return $this->hasOne(Person::class, 'dnaSampleId');
    }
}
