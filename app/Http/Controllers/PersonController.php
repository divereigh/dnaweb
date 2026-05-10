<?php

namespace App\Http\Controllers;

use App\Services\PersonDetailService;
use Inertia\Inertia;

class PersonController extends Controller
{
    public function __construct(private PersonDetailService $service) {}

    public function show(int $id)
    {
        $person = $this->service->get($id);
        abort_unless($person, 404, 'Person not found');

        $matches = [];
        $linkedSampleMissing = false;
        if ($person['dnaSampleId'] ?? null) {
            $rows = $this->service->eyeMatches((int) $person['dnaSampleId']);
            if ($rows === null) {
                $linkedSampleMissing = true;
            } else {
                $matches = $rows;
            }
        }

        $family = $this->service->children($id);
        $ancestryTrees = $this->service->ancestryTrees($id);
        $siblings = $this->service->siblings(
            $id,
            $person['father_id'] ?? null,
            $person['mother_id'] ?? null,
        );

        return Inertia::render('People/Show', [
            'person' => $person,
            'matches' => $matches,
            'linked_sample_missing' => $linkedSampleMissing,
            'family' => $family,
            'siblings' => $siblings,
            'ancestry_trees' => $ancestryTrees,
        ]);
    }
}
