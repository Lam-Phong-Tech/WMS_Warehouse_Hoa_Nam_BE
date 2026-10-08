<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Tests\TestCase;

class P06SnapshotTransactionTest extends TestCase
{
    public function test_top_level_snapshot_has_the_promised_database_mode(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $response = P06ReadSnapshot::run(function (array $snapshot) {
            $value = DB::selectOne('SELECT 1 AS value')->value;
            if (DB::getDriverName() === 'pgsql') {
                $this->assertSame('repeatable read', DB::selectOne("SELECT current_setting('transaction_isolation') AS value")->value);
                $this->assertSame('on', DB::selectOne("SELECT current_setting('transaction_read_only') AS value")->value);
            }

            return response()->json(['value' => $value, 'snapshot' => $snapshot]);
        });
        $this->assertSame(1, (int) $response->getData()->value);
        $this->assertSame('SINGLE_RESPONSE_DATABASE_SNAPSHOT', $response->getData()->snapshot->consistency);
        $this->assertFalse($response->getData()->snapshot->cross_request_snapshot);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_postgres_rejects_writes_inside_the_read_only_snapshot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertSame('sqlite', DB::getDriverName());

            return;
        }
        try {
            P06ReadSnapshot::run(function () {
                DB::statement('CREATE TABLE p06_forbidden_write (id INTEGER)');

                return response()->json([]);
            });
            $this->fail('Read-only snapshot accepted DDL.');
        } catch (QueryException $exception) {
            $this->assertSame('25006', $exception->errorInfo[0]);
        }
        $this->assertSame(0, DB::transactionLevel());
    }
}
