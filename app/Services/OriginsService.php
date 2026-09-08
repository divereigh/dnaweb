<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ancestry "origins" — the ethnicity estimate — for a DNA sample.
 *
 * The data is loaded by the Perl side (worker-origins.pl draining the
 * dna_origins_loaded queue, shelling out to load-dna.pl loadorigin).
 * This app only reads the results and asks for a load, the same
 * division of labour as dna_notes/pushreq.
 *
 * The one thing worth understanding before reading the rest: Ancestry
 * lets people restrict their ethnicity to "shared only", and such a kit
 * shows only the regions it has IN COMMON with whichever kit is looking
 * at it. So one fetch can be incomplete, a different eye reveals a
 * different slice, and the loader walks the eyes accumulating the union.
 * That is why a sample can sit at 62% and why "complete" means either
 * "we hold all 100%" or "every eye that could see it has been asked".
 */
class OriginsService
{
    /** Priority for work the web asks for — ahead of the bulk sweeps. */
    private const WEB_PRIORITY = 10;

    /**
     * One row of state for a sample: how much we hold, how far the eye
     * walk has got, and whether the queue is working on it. Everything
     * the page needs to decide between "show results" and "show
     * spinner".
     */
    public function status(int $sampleId): array
    {
        $row = DB::selectOne('
            SELECT sample, sampleName, regions, storedPercent,
                   eyesTotal, eyesTried, eyesRemaining,
                   queueStatus, attempts, loaded, complete, queued
            FROM v_origins_status
            WHERE sample = ?
        ', [$sampleId]);

        if (!$row) {
            return [
                'regions_count'  => 0,
                'stored_percent' => 0,
                'eyes_total'     => 0,
                'eyes_tried'     => 0,
                'eyes_remaining' => 0,
                'queue_status'   => '',
                'attempts'       => 0,
                'loaded'         => null,
                'complete'       => true,
                'queued'         => false,
                'loadable'       => false,
            ];
        }

        return [
            'regions_count'  => (int) $row->regions,
            'stored_percent' => (int) $row->storedPercent,
            'eyes_total'     => (int) $row->eyesTotal,
            'eyes_tried'     => (int) $row->eyesTried,
            'eyes_remaining' => (int) $row->eyesRemaining,
            'queue_status'   => (string) $row->queueStatus,
            'attempts'       => (int) $row->attempts,
            'loaded'         => $row->loaded,
            'complete'       => (bool) $row->complete,
            'queued'         => (bool) $row->queued,

            // Is there any point asking? False when nothing can ever
            // fetch this kit (no managed eye matches it), which is a
            // different message to the user than "still loading".
            'loadable'       => ((int) $row->eyesTotal) > 0,
        ];
    }

    /**
     * The stored breakdown, biggest share first, with the region names
     * resolved. Grouped by macro region on the page, but sorted here so
     * the ordering is stable regardless of grouping.
     */
    public function regions(int $sampleId): array
    {
        $rows = DB::select('
            SELECT o.regionKey, o.version, o.percentage,
                   r.regionName, r.macroRegionKey, r.macroRegionName
            FROM dna_origins o
            JOIN dna_region r ON r.regionKey = o.regionKey
                             AND r.version   = o.version
            WHERE o.sample = ?
            ORDER BY o.percentage DESC, r.regionName
        ', [$sampleId]);

        return array_map(fn ($r) => [
            'region_key'       => $r->regionKey,
            'region_name'      => $r->regionName,
            'macro_region_key' => $r->macroRegionKey,
            'macro_region'     => $r->macroRegionName,
            'percentage'       => (int) $r->percentage,
            'version'          => (int) $r->version,
        ], $rows);
    }

    /**
     * Decorate rows with `origin_icons` — the marked regions each row's
     * sample holds more than 0% of. One query for the whole page, same
     * shape as DnaSampleService::attachTrees().
     *
     * Only regions with dna_region.icon set produce anything, so this is
     * empty for almost every sample: the icons are for the few origins
     * worth spotting at a glance regardless of size (see
     * deploy/dna_region-icon.sql). A sample can carry several, and two
     * marked regions sharing one icon collapse to a single image whose
     * tooltip names both.
     *
     * Per-region, not per-macro-region — the whole point is that one
     * region under a heading can be marked while the rest of the heading
     * is not.
     *
     * An empty list means "no marked regions OR no origins ever loaded
     * for this sample"; the two are not distinguishable here, and most
     * samples have never been walked.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function decorateIcons(array &$rows, string $idKey): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$idKey] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        $bySample = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $found = DB::select('
                SELECT DISTINCT o.sample, r.icon, r.regionName
                FROM dna_origins o
                JOIN dna_region r ON r.regionKey = o.regionKey
                                 AND r.version   = o.version
                WHERE o.percentage > 0
                  AND r.icon IS NOT NULL
                  AND r.icon <> ?
                  AND o.sample IN (' . $in . ')
                ORDER BY r.icon, r.regionName
            ', array_merge([''], $ids));

            // sample => icon => [regionName, ...]; the inner grouping is
            // what collapses two marked regions sharing an icon into one
            // image while keeping both names for the tooltip.
            $names = [];
            foreach ($found as $f) {
                $names[(int) $f->sample][$f->icon][] = $f->regionName;
            }
            foreach ($names as $sample => $byIcon) {
                foreach ($byIcon as $icon => $regionNames) {
                    $bySample[$sample][] = [
                        'src'   => '/region-icons/' . $icon,
                        'label' => implode(', ', $regionNames),
                    ];
                }
            }
        }

        foreach ($rows as &$row) {
            $id = (int) ($row[$idKey] ?? 0);
            $row['origin_icons'] = $bySample[$id] ?? [];
        }
        unset($row);
    }

    /**
     * Ask for this sample's origins to be loaded, or for its eye walk to
     * be carried further.
     *
     * All the judgement lives in the stored procedure: it picks the eye,
     * does nothing when there is nothing to gain (already at 100%, or
     * every eye asked), and — the reason it is a procedure rather than
     * an upsert written here — leaves a `running` row strictly alone, so
     * we can never yank a job out from under a worker mid-fetch.
     *
     * Safe and cheap to call on every page view.
     */
    public function enqueue(int $sampleId): void
    {
        DB::statement('CALL sp_origins_enqueue(?, ?)', [$sampleId, self::WEB_PRIORITY]);
    }

    /**
     * Start the eye walk again from scratch: forget which eyes have
     * been asked, then enqueue. For the RELOAD button, when someone
     * wants to re-check a kit whose eyes were all exhausted (its
     * ethnicity may have changed, or its visibility setting has).
     */
    public function requeueAll(int $sampleId): void
    {
        DB::update('
            UPDATE dna_matches2
               SET originsLoaded = NULL
             WHERE sample2 = ? AND originsLoaded IS NOT NULL
        ', [$sampleId]);

        $this->enqueue($sampleId);
    }
}
