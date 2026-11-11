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
            $this->assertSame(array_map(fn (int $n): string => 'PX-2026110310000'.$n, range(0, 4)), $unique);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $db->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }

    public function test_postgresql_parallel_inbound_and_warranty_same_second_requests_allocate_unique_timestamp_codes(): void
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
            $db->statement('CREATE TABLE "'.$schema.'".inbound_daily_numbers (number_date date PRIMARY KEY, last_value bigint NOT NULL)');
            $db->statement('CREATE TABLE "'.$schema.'".inbound_draft_numbers (draft_id uuid PRIMARY KEY, doc_no varchar(64) UNIQUE, assigned_at timestamptz(6))');
            $db->statement('CREATE TABLE "'.$schema.'".warranty_daily_numbers (number_date date PRIMARY KEY, last_value bigint NOT NULL)');
            $db->statement('CREATE TABLE "'.$schema.'".warranty_case (warranty_case_code varchar(80) UNIQUE)');
            $drafts = array_map(fn (): string => (string) Str::uuid(), range(1, 5));
            foreach ($drafts as $draft) {
                $db->insert('INSERT INTO "'.$schema.'".mini_app_drafts VALUES (?, ?, ?, NULL)', [$draft, 'INBOUND', 'DRAFT']);
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
            if ($argv[2] === 'WARRANTY') {
                echo app(Modules\Warehouse\Services\WarrantyCaseNumber::class)->next();
            } else {
                echo app(Modules\Warehouse\Services\InboundDocumentNumber::class)->reserve($argv[2]);
            }
            PHP;
            foreach ([...$drafts, $drafts[0], 'WARRANTY', 'WARRANTY', 'WARRANTY'] as $arg) {
                $worker = new Process([PHP_BINARY, '-r', $code, $schema, $arg], base_path(), ['APP_ENV' => 'testing']);
                $worker->setInput(json_encode($db->getConfig(), JSON_THROW_ON_ERROR));
                $worker->setTimeout(90);
                $worker->start();
                $workers[] = $worker;
            }
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), 'Isolated worker failed, exit '.$worker->getExitCode());
                $outputs[] = trim($worker->getOutput());
            }
            $inboundNumbers = array_slice($outputs, 0, 6);
            $warrantyNumbers = array_slice($outputs, 6, 3);
            $this->assertSame($inboundNumbers[0], $inboundNumbers[5]);
            $uniqueInbound = array_values(array_unique($inboundNumbers));
            sort($uniqueInbound);
            $this->assertSame(array_map(fn (int $n): string => 'PN-2026110310000'.$n, range(0, 4)), $uniqueInbound);
            sort($warrantyNumbers);
            $this->assertSame(array_map(fn (int $n): string => 'BH-2026110310000'.$n, range(0, 2)), $warrantyNumbers);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $db->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }

    public function test_parallel_worker_processes_in_same_second_allocate_unique_pn_px_bh_codes_and_preserve_draft_replay(): void
    {
        $dbFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms_numbering_race_'.bin2hex(random_bytes(8)).'.sqlite';
        touch($dbFile);
        $pdo = new \PDO('sqlite:'.$dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 10000;');
        $pdo->exec('CREATE TABLE mini_app_drafts (document_id text PRIMARY KEY, operation text, status text, business_document_id text)');
        $pdo->exec('CREATE TABLE stock_documents (doc_no text UNIQUE)');
        $pdo->exec('CREATE TABLE inbound_daily_numbers (number_date text PRIMARY KEY, last_value integer NOT NULL)');
        $pdo->exec('CREATE TABLE inbound_draft_numbers (draft_id text PRIMARY KEY, doc_no text UNIQUE, assigned_at text)');
        $pdo->exec('CREATE TABLE outbound_daily_numbers (number_date text PRIMARY KEY, last_value integer NOT NULL)');
        $pdo->exec('CREATE TABLE outbound_draft_numbers (draft_id text PRIMARY KEY, doc_no text UNIQUE, assigned_at text)');
        $pdo->exec('CREATE TABLE warranty_daily_numbers (number_date text PRIMARY KEY, last_value integer NOT NULL)');
        $pdo->exec('CREATE TABLE warranty_case (warranty_case_code text UNIQUE)');

        $inDrafts = array_map(fn (): string => (string) Str::uuid(), range(1, 3));
        $outDrafts = array_map(fn (): string => (string) Str::uuid(), range(1, 3));
        $stmt = $pdo->prepare('INSERT INTO mini_app_drafts (document_id, operation, status, business_document_id) VALUES (?, ?, ?, NULL)');
        foreach ($inDrafts as $id) {
            $stmt->execute([$id, 'INBOUND', 'DRAFT']);
        }
        foreach ($outDrafts as $id) {
            $stmt->execute([$id, 'OUTBOUND', 'DRAFT']);
        }
        $pdo = null;

        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!$app->environment('testing')) { exit(2); }
        config([
            'database.default' => 'race_sqlite',
            'database.connections.race_sqlite' => [
                'driver' => 'sqlite',
                'database' => $argv[1],
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        Illuminate\Support\Facades\DB::connection('race_sqlite')->statement('PRAGMA busy_timeout = 10000');
        Illuminate\Support\Carbon::setTestNow('2026-11-03T10:00:00+07:00');
        $attempt = 0;
        while (true) {
            try {
                echo match ($argv[2]) {
                    'INBOUND' => app(Modules\Warehouse\Services\InboundDocumentNumber::class)->reserve($argv[3]),
                    'OUTBOUND' => app(Modules\Warehouse\Services\OutboundDocumentNumber::class)->reserve($argv[3]),
                    'WARRANTY' => app(Modules\Warehouse\Services\WarrantyCaseNumber::class)->next(),
                };
                break;
            } catch (\Throwable $e) {
                if (++$attempt >= 25 || !str_contains(strtolower($e->getMessage()), 'locked')) {
                    throw $e;
                }
                usleep(20000);
            }
        }
        PHP;

        $jobs = [
            ['INBOUND', $inDrafts[0]],
            ['INBOUND', $inDrafts[1]],
            ['INBOUND', $inDrafts[2]],
            ['INBOUND', $inDrafts[0]], // same draft replay
            ['OUTBOUND', $outDrafts[0]],
            ['OUTBOUND', $outDrafts[1]],
            ['OUTBOUND', $outDrafts[2]],
            ['OUTBOUND', $outDrafts[0]], // same draft replay
            ['WARRANTY', '1'],
            ['WARRANTY', '2'],
            ['WARRANTY', '3'],
        ];

        $workers = [];
        try {
            foreach ($jobs as [$type, $target]) {
                $worker = new Process([PHP_BINARY, '-r', $code, $dbFile, $type, $target], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(90);
                $worker->start();
                $workers[] = $worker;
            }

            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), 'Worker failed: '.$worker->getErrorOutput().' '.$worker->getOutput());
                $outputs[] = trim($worker->getOutput());
            }

            $inCodes = array_slice($outputs, 0, 4);
            $outCodes = array_slice($outputs, 4, 4);
            $bhCodes = array_slice($outputs, 8, 3);

            $this->assertSame($inCodes[0], $inCodes[3], 'Same inbound draft must return identical PN code.');
            $this->assertSame($outCodes[0], $outCodes[3], 'Same outbound draft must return identical PX code.');

            $uniqueIn = array_values(array_unique($inCodes));
            sort($uniqueIn);
            $this->assertSame(['PN-20261103100000', 'PN-20261103100001', 'PN-20261103100002'], $uniqueIn);

            $uniqueOut = array_values(array_unique($outCodes));
            sort($uniqueOut);
            $this->assertSame(['PX-20261103100000', 'PX-20261103100001', 'PX-20261103100002'], $uniqueOut);

            sort($bhCodes);
            $this->assertSame(['BH-20261103100000', 'BH-20261103100001', 'BH-20261103100002'], $bhCodes);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            @unlink($dbFile);
            @unlink($dbFile.'-wal');
            @unlink($dbFile.'-shm');
        }
    }
}
