<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base for tests that run against the local MariaDB snapshot.
 *
 * Run them with `composer test:data`; `composer test` does not, because
 * they need a database a checkout does not come with. Each is skipped
 * rather than failed when the snapshot is missing, so a box without one
 * still gets a green sqlite suite.
 *
 * Every test runs inside a transaction that is rolled back afterwards,
 * so a test may write freely — the app's raw DB::update()/DB::insert()
 * calls join the same connection's transaction like anything else.
 */
abstract class DataTestCase extends TestCase
{
    /**
     * Ports we will not run against, whatever the configuration says.
     * 3308 is the SSH tunnel to production; these tests write.
     */
    private const FORBIDDEN_PORTS = [3308];

    /** @var array<int,int|null> memo of minimum-matches threshold => sample id */
    private static array $sampleMemo = [];

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        // Checked before parent::setUp(), which is where anything would
        // first reach for a connection. phpunit.data.xml pins the port,
        // so this should be unreachable — it is here because the cost of
        // being wrong is writing to production.
        $port = (int) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? getenv('DB_PORT') ?: 0);

        if (in_array($port, self::FORBIDDEN_PORTS, true)) {
            $this->fail(
                "Data tests are pointed at port $port, the production tunnel. Refusing to run. ".
                'Check DB_PORT in phpunit.data.xml.'
            );
        }

        parent::setUp();

        // The transaction is opened by hand rather than through the
        // DatabaseTransactions trait, whose hook connects during
        // parent::setUp() — too early to skip cleanly, so a box with no
        // snapshot got a PDOException per test instead of a skip.
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'No local MariaDB snapshot on '.config('database.connections.mysql.host').":$port. ".
                'Restore one with: zcat ~/ancestry/program/dbdump/dnaweb.dbdump-*.gz | mariadb dnaweb'
            );
        }

        if (! $this->tableHasRows('dna_matches2')) {
            $this->markTestSkipped('The local database is reachable but holds no dna_matches2 rows.');
        }

        DB::beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            DB::rollBack();
            $this->inTransaction = false;
        }

        parent::tearDown();
    }

    private function tableHasRows(string $table): bool
    {
        try {
            return (bool) DB::selectOne("SELECT 1 AS x FROM `$table` LIMIT 1");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A sample with at least $minMatches rows in dna_matches2, so a test
     * can walk several chunks. Found rather than hard-coded, because the
     * snapshot is whatever dump happens to be restored.
     *
     * Deliberately the *first* qualifying sample, not the biggest.
     * `GROUP BY sample1` over the whole table takes ~24s; walking
     * distinct sample1 values instead is a loose scan of the primary
     * key's leading column and each count is a range scan, which brings
     * it under 60ms. Taking a small qualifying kit also keeps the
     * last-chunk tests off a deep OFFSET — on the largest kit the final
     * page sits at OFFSET 90,000 and takes a second on its own.
     */
    protected function sampleWithManyMatches(int $minMatches = 200): int
    {
        if (array_key_exists($minMatches, self::$sampleMemo)) {
            return self::$sampleMemo[$minMatches]
                ?? $this->markTestSkipped("No sample in the snapshot has $minMatches or more matches.");
        }

        self::$sampleMemo[$minMatches] = null;

        foreach (DB::select('SELECT DISTINCT sample1 FROM dna_matches2 ORDER BY sample1 LIMIT 200') as $candidate) {
            $count = (int) DB::selectOne(
                'SELECT COUNT(*) AS c FROM dna_matches2 WHERE sample1 = ?', [$candidate->sample1]
            )->c;

            if ($count >= $minMatches) {
                return self::$sampleMemo[$minMatches] = (int) $candidate->sample1;
            }
        }

        $this->markTestSkipped("No sample in the snapshot has $minMatches or more matches.");
    }

    /**
     * The asset version Inertia stamps on a response. Asking the real
     * middleware rather than recomputing the hash, so this cannot drift
     * from what the app actually sends; without a matching version every
     * request comes back 409.
     */
    protected function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }
}
