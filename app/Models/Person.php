<?php

namespace App\Models;

use App\Support\PhoneticEncoder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Keep fullName_phonetic in lockstep with fullName on every
     * Eloquent write. Raw DB::update queries bypass this — coverage
     * for those lives in the Perl loaders and the nightly
     * `dna:backfill-phonetic --where-null` sweep.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => [
                'fullName' => $value,
                'fullName_phonetic' => PhoneticEncoder::encode($value ?? ''),
            ],
        );
    }
}
