<?php

namespace App\Console\Commands;

use App\Support\PhoneticEncoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPhonetic extends Command
{
    protected $signature = 'dna:backfill-phonetic {--where-null : only update rows still missing phonetic}';
    protected $description = 'Populate dna_samples.displayName_phonetic and people.fullName_phonetic from PhoneticEncoder.';

    public function handle(): int
    {
        $this->fill('dna_samples', 'id', 'displayName', 'displayName_phonetic');
        $this->fill('people',      'id', 'fullName',    'fullName_phonetic');
        return 0;
    }

    /**
     * Stream through the table by primary key, encoding in PHP and
     * writing back in 1000-row transactions. Linear in row count and
     * survives over an SSH tunnel — slow on dna_samples (2.4M rows)
     * but only runs once. Subsequent re-runs with --where-null catch
     * anything the loaders / observers missed.
     */
    private function fill(string $table, string $pk, string $col, string $phonCol): void
    {
        $whereNull = (bool) $this->option('where-null');

        $countSql = "SELECT COUNT(*) AS c FROM $table"
                  . ($whereNull ? " WHERE $phonCol IS NULL" : '');
        $total = (int) DB::selectOne($countSql)->c;
        $this->info("$table.$phonCol: $total rows to update");
        if ($total === 0) return;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $batchSize = 1000;
        $last = 0;
        while (true) {
            $sql = "SELECT $pk, $col FROM $table"
                 . ($whereNull
                    ? " WHERE $phonCol IS NULL AND $pk > ?"
                    : " WHERE $pk > ?")
                 . " ORDER BY $pk LIMIT $batchSize";
            $rows = DB::select($sql, [$last]);
            if (!$rows) break;

            DB::transaction(function () use ($rows, $table, $pk, $col, $phonCol, &$last, $bar) {
                foreach ($rows as $r) {
                    $code = PhoneticEncoder::encode($r->$col);
                    DB::update(
                        "UPDATE $table SET $phonCol = ? WHERE $pk = ?",
                        [$code, $r->$pk]
                    );
                    $last = $r->$pk;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
    }
}
