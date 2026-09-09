<?php

namespace App\Http\Controllers;

use App\Services\DnaSampleService;
use App\Services\OriginsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OriginsController extends Controller
{
    public function __construct(
        private OriginsService $origins,
        private DnaSampleService $samples,
    ) {}

    /**
     * Ancestry's ethnicity estimate for one sample.
     *
     * Opening the page is what asks for the load. If the sample is
     * already complete the enqueue is a no-op; otherwise the worker
     * picks it up within seconds and the Vue side polls `status` until
     * it settles, showing regions as they accumulate.
     */
    public function show(Request $request, int $id)
    {
        $sample = $this->samples->get($id);
        abort_unless($sample, 404, 'DNA sample not found');

        // Skip the enqueue on partial reloads. The Vue side polls with
        // `only: ['status']`, and re-running the procedure every few
        // seconds would be pointless work — the first call already put
        // the sample on the queue, and the worker owns it from there.
        // Same reasoning as DnaMatchesController::index.
        $isPartial = $request->header('X-Inertia-Partial-Data') !== null;

        if (! $isPartial) {
            $this->origins->enqueue($id);
        }

        return Inertia::render('Dna/Origins', [
            'sample' => $sample,

            // Closures: a poll asking `only: ['status']` must not drag
            // the region list along with it.
            'status' => fn () => $this->origins->status($id),
            'regions' => fn () => $this->origins->regions($id),
        ]);
    }

    /**
     * Re-check a sample from scratch: clear the record of which eyes
     * have been asked, then enqueue. Useful when every eye has already
     * been tried — a kit's estimate or its visibility setting can
     * change, and nothing else would ever look again.
     */
    public function requeue(int $id)
    {
        abort_unless($this->samples->get($id), 404, 'DNA sample not found');
        $this->origins->requeueAll($id);

        return back();
    }
}
