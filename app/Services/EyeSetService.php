<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The one answer to "which kits are eyes?".
 *
 * "Is an eye" and "has a live Ancestry session" are different questions, and
 * conflating them is what this class exists to stop. dna_samples.managed
 * answers the second: it points at session.id, and the loaders NULL it when
 * access to a kit is lost (see load-dna.pl in ancestry-program, which joins
 * session on it). Keying the UI off managed alone made a kit vanish the
 * moment its session went away, taking every match already loaded through it
 * out of reach — six kits and ~90,000 matches were hidden that way.
 *
 * The marker for the first question is mgmtsample on the two loader work
 * queues: the kits the loaders have actually run as. dna_matches2.sample1 is
 * NOT usable here — match-of-match loading writes ordinary matches into
 * sample1 too, so ~2.4M of the 2.6M samples appear there.
 *
 * Anything deciding *whether a fetch can still happen* wants the session
 * question instead, and should keep joining session on managed —
 * DnaSampleService::requeueAll() is the example to copy.
 */
class EyeSetService
{
    /** @var array<int>|null memoised for the life of the request */
    private ?array $ids = null;

    /** @return array<int> */
    public function ids(): array
    {
        return $this->ids ??= array_map(
            fn ($r) => (int) $r->id,
            DB::select('
                SELECT id FROM dna_samples WHERE managed IS NOT NULL
                UNION
                SELECT DISTINCT mgmtsample FROM dna_match2match_loaded
                UNION
                SELECT DISTINCT mgmtsample FROM dna_origins_loaded
                 WHERE mgmtsample IS NOT NULL
            ')
        );
    }

    public function contains(int $sampleId): bool
    {
        return in_array($sampleId, $this->ids(), true);
    }

    /**
     * A SQL predicate for the given column, with the ids inlined.
     *
     * Inlined rather than bound or expressed as a correlated subquery
     * because it is evaluated per row of match lists tens of thousands of
     * rows long, and the set is only ~114 integers straight from the
     * database. Returns '0' when there are no eyes at all, so callers can
     * drop it into a SELECT list or a WHERE clause unconditionally.
     */
    public function sqlIn(string $column): string
    {
        $ids = $this->ids();

        return $ids ? $column . ' IN (' . implode(',', $ids) . ')' : '0';
    }
}
