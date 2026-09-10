<?php

namespace Tests\Data;

use App\Services\DnaSampleService;
use Illuminate\Support\Facades\DB;
use Tests\DataTestCase;

/**
 * The arithmetic behind the infinite-scrolling match list.
 *
 * `/dna/{id}/matches` no longer runs a COUNT, so nothing on the page
 * cross-checks how the chunks line up: if the walk skips or repeats a
 * row, the list still looks like a list. It has already happened once —
 * asking listMatches() for `$pageSize + 1` rows by inflating $pageSize
 * also inflated the OFFSET, dropping one match at every chunk boundary
 * and returning an empty final page. These are the assertions that
 * would have caught it.
 */
class MatchChunkingTest extends DataTestCase
{
    private const CHUNK = 50;

    private function service(): DnaSampleService
    {
        return app(DnaSampleService::class);
    }

    /**
     * Walk the list the way the page does and compare against one
     * straight ordered read of the same range. This is the heart of it:
     * chunking must be a pure partition of the underlying order.
     */
    public function test_walking_chunks_yields_the_same_rows_as_one_straight_read(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 6);
        $svc = $this->service();
        $chunks = 6;

        $walked = [];
        for ($page = 1; $page <= $chunks; $page++) {
            $rows = $svc->listMatches($sample, $page, self::CHUNK, null, '', null, 'ALL', null, [], [], extra: 1);
            // The lookahead row is a probe, never displayed.
            $walked = array_merge($walked, array_column(array_slice($rows, 0, self::CHUNK), 'other_id'));
        }

        $straight = array_column(
            $svc->listMatches($sample, 1, self::CHUNK * $chunks, null, '', null, 'ALL', null, [], []),
            'other_id'
        );

        $this->assertSame(
            $straight,
            $walked,
            'Chunked walk diverged from a straight read — rows are being skipped or reordered at a chunk boundary.'
        );
        $this->assertSame(
            count($walked),
            count(array_unique($walked)),
            'The chunked walk returned the same row more than once.'
        );
    }

    /**
     * The lookahead row is what replaced the COUNT, so "is there more"
     * has to be right at both ends: true in the middle of the list, and
     * false on the final chunk — which must still hand back its rows.
     */
    public function test_the_lookahead_row_reports_more_pages_honestly(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 3);
        $svc = $this->service();

        $total = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM dna_matches2 WHERE sample1 = ?', [$sample]
        )->c;

        $first = $svc->listMatches($sample, 1, self::CHUNK, null, '', null, 'ALL', null, [], [], extra: 1);
        $this->assertGreaterThan(self::CHUNK, count($first), 'First chunk should see a row beyond its page.');

        $lastPage = (int) ceil($total / self::CHUNK);
        $last = $svc->listMatches($sample, $lastPage, self::CHUNK, null, '', null, 'ALL', null, [], [], extra: 1);

        $expected = $total - (($lastPage - 1) * self::CHUNK);
        $this->assertCount($expected, $last, 'The final chunk lost rows — check the OFFSET arithmetic.');
        $this->assertLessThanOrEqual(self::CHUNK, count($last), 'The final chunk should report no page beyond it.');

        $beyond = $svc->listMatches($sample, $lastPage + 1, self::CHUNK, null, '', null, 'ALL', null, [], [], extra: 1);
        $this->assertSame([], $beyond, 'Past the end should be empty, not wrapped.');
    }

    /**
     * `extra` must widen the LIMIT without moving the OFFSET. Stated
     * directly, because this is the exact mistake that was made.
     */
    public function test_the_lookahead_does_not_move_the_offset(): void
    {
        $sample = $this->sampleWithManyMatches(self::CHUNK * 3);
        $svc = $this->service();

        foreach ([1, 2, 3] as $page) {
            $plain = $svc->listMatches($sample, $page, self::CHUNK, null, '', null, 'ALL', null, [], []);
            $probed = $svc->listMatches($sample, $page, self::CHUNK, null, '', null, 'ALL', null, [], [], extra: 1);

            $this->assertSame(
                array_column($plain, 'other_id'),
                array_column(array_slice($probed, 0, self::CHUNK), 'other_id'),
                "Page $page differs once a lookahead row is requested — extra is moving the offset."
            );
        }
    }

    /**
     * matchRows() re-reads rows by id after a write. It shares its SQL
     * with listMatches() through selectMatchRows(), and the page splices
     * its output straight into the list, so the two have to agree on
     * every key and value.
     */
    public function test_rows_read_by_id_match_the_rows_read_by_page(): void
    {
        $sample = $this->sampleWithManyMatches();
        $svc = $this->service();

        $paged = $svc->listMatches($sample, 1, 5, null, '', null, 'ALL', null, [], []);
        $this->assertNotEmpty($paged);

        $byId = collect($svc->matchRows($sample, array_column($paged, 'other_id')))
            ->keyBy('other_id');

        $this->assertCount(count($paged), $byId, 'matchRows returned a different number of rows.');

        foreach ($paged as $row) {
            $other = $byId[$row['other_id']] ?? null;
            $this->assertNotNull($other, "matchRows lost row {$row['other_id']}.");
            $this->assertSame(
                array_keys($row),
                array_keys($other),
                "matchRows built a different shape for row {$row['other_id']}."
            );
            $this->assertEquals($row, $other, "matchRows disagreed with listMatches on row {$row['other_id']}.");
        }
    }

    public function test_rows_read_by_id_ignore_ids_that_are_not_matches(): void
    {
        $svc = $this->service();
        $sample = $this->sampleWithManyMatches();

        $this->assertSame([], $svc->matchRows($sample, []));
        $this->assertSame([], $svc->matchRows($sample, [0, -1]));
        $this->assertSame([], $svc->matchRows($sample, [PHP_INT_MAX]));
    }
}
