<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertPersonRequest;
use App\Models\DnaSample;
use App\Models\Person;
use App\Services\PersonDetailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PersonController extends Controller
{
    public function __construct(private PersonDetailService $service) {}

    public function upsertForSample(UpsertPersonRequest $request, int $sampleId): RedirectResponse
    {
        $sample = DnaSample::query()->where('id', $sampleId)->where('disabled', 0)->first();
        abort_unless($sample, 404, 'DNA sample not found');

        $data = $request->validated();
        $years = $request->birthYears();

        try {
            $link = $request->ancestryLink();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ancestry_url' => $e->getMessage()])->withInput();
        }

        $person = Person::query()->firstOrNew(['dnaSampleId' => $sampleId]);
        $person->fullName = $data['fullName'];
        $person->minBirth = $years['minBirth'];
        $person->maxBirth = $years['maxBirth'];
        $person->death    = $data['death'] ?? null;
        $person->gender   = $data['gender'] ?? null;

        // people has UNIQUE(fullName, alt) — bail before MySQL throws a
        // 23000 with a generic 500. Real duplicate handling needs us to
        // pick an alt value (loaders do this), which the UI doesn't yet.
        $alt = (int) ($person->alt ?? 0);
        $clashQuery = DB::table('people')
            ->where('fullName', $person->fullName)
            ->where('alt', $alt);
        if ($person->exists) {
            $clashQuery->where('id', '!=', $person->id);
        }
        if ($clashQuery->exists()) {
            return back()->withErrors([
                'fullName' => "A person named \"{$person->fullName}\" already exists. Duplicates are stored with a different alt value, which this dialog can't set yet.",
            ])->withInput();
        }

        $person->save();

        if ($link) {
            DB::statement('
                INSERT INTO gedcom_people (atreeid, ancestryid, peopleid)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE peopleid = VALUES(peopleid)
            ', [$link['atreeid'], $link['ancestryid'], $person->id]);
        }

        return back();
    }

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
