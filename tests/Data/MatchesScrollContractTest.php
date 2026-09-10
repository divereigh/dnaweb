<?php

namespace Tests\Data;

use App\Models\User;
use App\Services\DnaSampleService;
use Illuminate\Support\Facades\DB;
use Tests\DataTestCase;

/**
 * The wire contract between DnaMatchesController and Inertia's
 * InfiniteScroll component.
 *
 * None of this is visible in the Vue file: the component is handed a
 * prop name and works out the rest from `scrollProps` and `mergeProps`
 * in the page object. Get a key wrong and it does not error, it just
 * stops fetching — the list silently ends at the first chunk. So the
 * shape is asserted here rather than left to a browser to notice.
 */
class MatchesScrollContractTest extends DataTestCase
{
    private const CHUNK = 50;

    private function actingAsAnyUser(): static
    {
        $user = User::query()->first();

        if (! $user) {
            $this->markTestSkipped('No user in the snapshot to authenticate as.');
        }

        return $this->actingAs($user);
    }

    private function load(int $sample, array $query = [], array $headers = [])
    {
        return $this->actingAsAnyUser()->get(
            route('dna.matches', $sample).(($q = http_build_query($query)) ? "?$q" : ''),
            $headers + ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()]
        );
    }

    private function partial(int $sample, string $only, array $query = [], array $headers = [])
    {
        return $this->load($sample, $query, $headers + [
            'X-Inertia-Partial-Data' => $only,
            'X-Inertia-Partial-Component' => 'Dna/Matches',
        ]);
    }

    public function test_the_first_load_describes_a_scrollable_list(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 3);
        $page = $this->load($sample)->assertOk()->json();

        $this->assertSame(
            ['matches.data'],
            $page['mergeProps'] ?? null,
            'Inertia must be told to append into matches.data, or each chunk replaces the last.'
        );
        $this->assertSame(
            ['matches.data.other_id'],
            $page['matchPropsOn'] ?? null,
            'Without a match key, a row the loaders shift between requests can appear twice.'
        );

        $meta = $page['scrollProps']['matches'] ?? null;
        $this->assertNotNull($meta, 'No scroll metadata — InfiniteScroll has nothing to page with.');
        $this->assertSame('page', $meta['pageName'], 'The component sends this as the query parameter name.');
        $this->assertSame(1, $meta['currentPage']);
        $this->assertNull($meta['previousPage']);
        $this->assertSame(2, $meta['nextPage']);
        $this->assertFalse($meta['reset']);

        $matches = $page['props']['matches'];
        $this->assertSame(['data', 'has_more'], array_keys($matches));
        $this->assertCount(self::CHUNK, $matches['data']);
        $this->assertTrue($matches['has_more']);
    }

    public function test_the_pager_props_are_gone(): void
    {
        $sample = $this->sampleWithManyMatches();
        $props = $this->load($sample)->assertOk()->json('props');

        foreach (['total', 'pages', 'page'] as $key) {
            $this->assertArrayNotHasKey($key, $props, "`$key` is back — that means a COUNT is being run again.");
        }
    }

    /**
     * row_patch is Inertia::optional() so it is only ever built when a
     * partial asks for it. As a plain closure it was resolved on every
     * full load, shipping an empty array with each one.
     */
    public function test_row_patch_is_not_built_unless_asked_for(): void
    {
        $sample = $this->sampleWithManyMatches();

        $this->assertArrayNotHasKey('row_patch', $this->load($sample)->assertOk()->json('props'));

        $first = $this->load($sample)->json('props.matches.data.0.other_id');
        $props = $this->partial($sample, 'row_patch', ['patch' => [$first]])->assertOk()->json('props');

        $this->assertSame(['errors', 'row_patch'], array_keys($props), 'A row_patch reload should carry nothing else.');
        $this->assertCount(1, $props['row_patch']);
        $this->assertSame($first, $props['row_patch'][0]['other_id']);
    }

    public function test_an_append_carries_only_the_next_chunk(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 3);

        $firstIds = array_column($this->load($sample)->json('props.matches.data'), 'other_id');

        $next = $this->partial($sample, 'matches', ['page' => 2], [
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'append',
        ])->assertOk()->json();

        $this->assertSame(['errors', 'matches'], array_keys($next['props']), 'An append should not rebuild the page.');

        $secondIds = array_column($next['props']['matches']['data'], 'other_id');
        $this->assertCount(self::CHUNK, $secondIds);
        $this->assertSame([], array_intersect($firstIds, $secondIds), 'Chunks 1 and 2 overlap.');

        $meta = $next['scrollProps']['matches'];
        $this->assertSame(2, $meta['currentPage']);
        $this->assertSame(1, $meta['previousPage']);
        $this->assertSame(3, $meta['nextPage']);
    }

    /**
     * Walk the chunks the way a scrolling browser does and compare the
     * result against one straight read of the same range.
     *
     * This is the assertion the page lost when the pager went: with no
     * total on screen, a chunk boundary that skips a row still renders
     * a perfectly plausible list. The service has its own version of
     * this check, but the bug that prompted it lived in how the
     * controller *called* the service, so it has to be asserted through
     * a real request too.
     */
    public function test_scrolling_through_chunks_visits_every_row_exactly_once(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 4);
        $chunks = 4;

        $walked = [];
        for ($page = 1; $page <= $chunks; $page++) {
            $rows = $this->partial($sample, 'matches', ['page' => $page], [
                'X-Inertia-Infinite-Scroll-Merge-Intent' => 'append',
            ])->assertOk()->json('props.matches.data');

            $walked = array_merge($walked, array_column($rows, 'other_id'));
        }

        $straight = array_column(
            app(DnaSampleService::class)
                ->listMatches($sample, 1, self::CHUNK * $chunks, null, '', null, 'ALL', null, [], []),
            'other_id'
        );

        $this->assertSame(
            $straight,
            $walked,
            'Scrolling skipped or reordered rows — every chunk looked fine on its own.'
        );
        $this->assertSame(count($walked), count(array_unique($walked)), 'A row was served twice while scrolling.');
    }

    /**
     * The end of the list is the only thing telling the component to
     * stop. It must arrive with the last chunk's rows, not instead of
     * them.
     */
    public function test_the_last_chunk_reports_no_next_page_but_still_has_rows(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 3);

        $total = (int) DB::selectOne('SELECT COUNT(*) AS c FROM dna_matches2 WHERE sample1 = ?', [$sample])->c;
        $lastPage = (int) ceil($total / self::CHUNK);

        $page = $this->partial($sample, 'matches', ['page' => $lastPage])->assertOk()->json();

        $this->assertNotEmpty($page['props']['matches']['data'], 'The last chunk came back empty.');
        $this->assertCount($total - (($lastPage - 1) * self::CHUNK), $page['props']['matches']['data']);
        $this->assertFalse($page['props']['matches']['has_more']);
        $this->assertNull($page['scrollProps']['matches']['nextPage'], 'The list never tells the component to stop.');
    }

    /**
     * Changing a filter is a new list, not more of the old one. The
     * reset flag is how the component knows to throw away what it has.
     */
    public function test_a_filter_change_asks_for_a_reset(): void
    {
        $sample = $this->sampleWithManyMatches();

        $meta = $this->partial($sample, 'matches', ['side' => 'MATERNAL'], [
            'X-Inertia-Reset' => 'matches',
        ])->assertOk()->json('scrollProps.matches');

        $this->assertTrue($meta['reset'], 'Without this the first chunk of the new filter appends to the old list.');
    }
}
