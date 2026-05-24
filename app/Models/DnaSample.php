<?php

namespace App\Models;

use App\Support\PhoneticEncoder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Keep displayName_phonetic in lockstep with displayName on every
     * Eloquent write. Raw DB::update queries bypass this — coverage
     * for those lives in the Perl loaders and the nightly
     * `dna:backfill-phonetic --where-null` sweep.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => [
                'displayName' => $value,
                'displayName_phonetic' => PhoneticEncoder::encode($value ?? ''),
            ],
        );
    }
}
