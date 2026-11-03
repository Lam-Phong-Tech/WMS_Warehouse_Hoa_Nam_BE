<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class P05NumberingConcurrencyTest extends TestCase
{
    public function test_postgresql_parallel_drafts_and_same_draft_requests_reserve_unique_stable_numbers(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real date-row locks require PostgreSQL.');
        }
        $db = DB::connection();
        $this->assertSame('testing', app()->environment());
        $this->assertContains($db->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->assertStringContainsString('test', $db->getDatabaseName());
        $schema = 'p05_race_'.bin2hex(random_bytes(8));
        $db->statement('CREATE SCHEMA "'.$schema.'"');
        $workers = [];
        try {
            $db->statement('CREATE TABLE "'.$schema.'".mini_app_drafts (document_id uuid PRIMARY KEY, operation text, status text, business_document_id uuid)');
            $db->statement('CREATE TABLE "'.$schema.'".stock_documents (doc_no varchar(80) UNIQUE)');
            $db->statement('CREATE TABLE "'.$schema.'".outbound_daily_numbers (number_date date PRIMARY KEY, last_value bigint NOT NULL)');
            $db->statement('CREATE TABLE "'.$schema.'".outbound_draft_numbers (draft_id uuid PRIMARY KEY, doc_no varchar(64) UNIQUE, assigned_at timestamptz(6))');
            $drafts = array_map(fn (): string => (string) Str::uuid(), range(1, 5));
            foreach ($drafts as $draft) {
                $db->insert('INSERT INTO "'.$schema.'".mini_app_drafts VALUES (?, ?, ?, NULL)', [$draft, 'OUTBOUND', 'DRAFT']);
            }
            $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (!$app->environment('testing') || !preg_match('/^p05_race_[a-f0-9]{16}$/D', $argv[1])) { exit(2); }
            $config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            $config['search_path'] = $argv[1];
            config(['database.default'=>'p05_race','database.connections.p05_race'=>$config]);
            Illuminate\Support\Carbon::setTestNow('2026-11-03T10:00:00+07:00');
            echo app(Modules\Warehouse\Services\OutboundDocumentNumber::class)->reserve($argv[2]);
            PHP;
            foreach ([...$drafts, $drafts[0], $drafts[0]] as $draft) {
                $worker = new Process([PHP_BINARY, '-r', $code, $schema, $draft], base_path(), ['APP_ENV' => 'testing']);
                $worker->setInput(json_encode($db->getConfig(), JSON_THROW_ON_ERROR));
                $worker->setTimeout(90);
                $worker->start();
                $workers[] = $worker;
            }
            $numbers = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), 'Isolated PX worker failed, exit '.$worker->getExitCode());
                $numbers[] = trim($worker->getOutput());
            }
            $this->assertCount(5, array_unique($numbers));
            $this->assertSame($numbers[0], $numbers[5]);
            $this->assertSame($numbers[0], $numbers[6]);
            $unique = array_values(array_unique($numbers));
            sort($unique);
            $this->assertSame(array_map(fn (int $n): string => 'PX-20261103-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, 5)), $unique);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $db->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
