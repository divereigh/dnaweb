<?php

namespace App\Http\Controllers;

use App\Services\EyeMatchService;
use App\Services\EyeSetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EyesController extends Controller
{
    public function __construct(
        private EyeMatchService $service,
        private EyeSetService $eyeSet,
    ) {}

    public function index(Request $request)
    {
        // `cluster_evidence` is only ever wanted by the ParentSide-mapping
        // editor, which asks for it with a partial reload
        // (only: ['cluster_evidence'], data: { evidence: <eye id> }). A
        // plain closure still runs on a full page load, so the guard is the
        // `evidence` parameter rather than the laziness: no parameter, no
        // queries, prop comes back null. The editor pays for one eye's two
        // small queries at the moment it opens, and nobody else pays at all.
        $evidenceFor = (int) $request->input('evidence') ?: null;

        return Inertia::render('Eyes/Index', [
            'eyes' => $this->service->listEyes(),
            'cluster_evidence' => fn () => $evidenceFor && $this->eyeSet->contains($evidenceFor)
                ? $this->service->clusterEvidence($evidenceFor)
                : null,
        ]);
    }

    /**
     * Set (or clear) our own answer to "which of this kit's two clusters is
     * the paternal one".
     *
     * Writes `dna_samples.paternalClusterOverride` and nothing else —
     * Ancestry's `paternalCluster` is the loaders' column and stays exactly
     * as load-dna.pl left it. See deploy/paternal-cluster-override.sql for
     * why the override exists and App\Support\Sql for how readers resolve
     * the two against each other.
     *
     * Restricted to eyes: the mapping is only ever consumed through an eye's
     * point of view (it is what turns that eye's per-match cluster codes
     * into sides), so an override on a non-eye kit could never be read.
     */
    public function updateParentSide(Request $request, int $id)
    {
        // Normalised before validating, not after: Ancestry stores these
        // codes lowercase in both paternalCluster and matchClusterCode and
        // every resolver compares them as such, so lowercase is the only
        // form worth storing. Doing it here means a hand-rolled 'P1' is
        // accepted and folded rather than bounced by the `in:` rule.
        $request->merge([
            'paternalClusterOverride' => is_string($request->input('paternalClusterOverride'))
                ? strtolower(trim($request->input('paternalClusterOverride')))
                : $request->input('paternalClusterOverride'),
        ]);

        $data = $request->validate([
            'paternalClusterOverride' => ['nullable', 'string', 'in:p1,p2'],
        ]);

        abort_unless($this->eyeSet->contains($id), 404, 'Eye not found');

        DB::update(
            'UPDATE dna_samples SET paternalClusterOverride = ? WHERE id = ?',
            [$data['paternalClusterOverride'] ?? null, $id]
        );

        return back();
    }
}
