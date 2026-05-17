<?php

namespace App\Console\Commands;

use App\Services\DnaSampleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DnaEnqueueMatches extends Command
{
    protected $signature = 'dna:enqueue
        {sample* : One or more dna_samples.id values}
        {--priority=10 : Queue priority (lower = sooner; web visits use 10)}';

    protected $description = 'Enqueue match-of-match loading for one or more DNA samples. Workers pick them up automatically.';

    public function handle(DnaSampleService $service): int
    {
        $samples = array_map('intval', $this->argument('sample'));
        $priority = (int) $this->option('priority');

        $total = 0;
        foreach ($samples as $id) {
            $before = $this->pendingCount($id);
            $service->enqueueForSample($id);
            $after = $this->pendingCount($id);
            $added = $after - $before;
            $this->line(sprintf(
                '  sample %-8d : %d pending pairs (%s)',
                $id,
                $after,
                $added > 0 ? "+$added" : 'no change',
            ));
            $total += $added;
        }
        $this->info(sprintf('Enqueued %d new pair%s across %d sample%s.',
            $total, $total === 1 ? '' : 's',
            count($samples), count($samples) === 1 ? '' : 's',
        ));
        $this->info('Workers will process these in priority/age order. Check progress with:');
        $this->line('  journalctl -u "match2match-worker@*" -f');
        return self::SUCCESS;
    }

    private function pendingCount(int $sampleId): int
    {
        $r = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM dna_match2match_loaded
            WHERE othsample = ? AND status = ?
        ', [$sampleId, 'pending']);
        return (int) ($r?->c ?? 0);
    }
}
