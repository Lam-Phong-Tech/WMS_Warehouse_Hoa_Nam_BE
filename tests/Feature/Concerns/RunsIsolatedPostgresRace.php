<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Explicit loopback disposable PostgreSQL only; never used by SQLite suites. */
trait RunsIsolatedPostgresRace
{
    use DatabaseTruncation;

    /** Check the resolved target before DatabaseTruncation can migrate or wipe. */
    protected function beforeTruncatingDatabase(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame('p12_contract_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertEmpty($connection->getConfig('url'));
        $this->assertSame(0, $connection->transactionLevel());
    }

    private function runDatabaseRace(string $lockedTable, callable $first, callable $second): array
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame('p12_contract_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'));
        $prefix = sys_get_temp_dir().'/backlog-race-'.bin2hex(random_bytes(10));
        $label = 'backlog-race-'.bin2hex(random_bytes(10));
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pipes === false) {
            throw new RuntimeException('Cannot create isolated race barrier');
        }
        stream_set_timeout($pipes[0], 15);
        stream_set_timeout($pipes[1], 15);
        DB::disconnect();
        $children = [];
        $blocked = false;
        try {
            foreach ([$first, $second] as $index => $work) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork isolated race worker');
                }
                if ($pid === 0) {
                    fclose($pipes[0]);
                    try {
                        DB::purge();
                        DB::statement("SELECT set_config('application_name', ?, false)", [$label.'-'.$index]);
                        DB::statement("SET lock_timeout = '8s'");
                        $held = false;
                        if ($index === 0) {
                            DB::listen(function (QueryExecuted $query) use (&$held, $lockedTable, $pipes): void {
                                if (! $held && str_contains(strtolower($query->sql), 'for update')
                                    && str_contains($query->sql, $lockedTable)) {
                                    $held = true;
                                    fwrite($pipes[1], 'L');
                                    fflush($pipes[1]);
                                    if (fread($pipes[1], 1) !== 'R') {
                                        throw new RuntimeException('Race barrier was not released');
                                    }
                                }
                            });
                        }
                        $result = $work();
                        file_put_contents($prefix.'-'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (Throwable $error) {
                        file_put_contents($prefix.'-'.$index.'.json', json_encode(['exception' => $error::class]));
                        exit(1);
                    }
                }
                $children[$index] = $pid;
                if ($index === 0 && fread($pipes[0], 1) !== 'L') {
                    throw new RuntimeException('First worker did not acquire the parent lock');
                }
            }
            // Observe an actual PostgreSQL lock wait, not an assumed timing race.
            $deadline = microtime(true) + 4;
            do {
                $row = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ? AND datname = current_database()', [$label.'-1']);
                $blocked = $row !== null && $row->wait_event_type === 'Lock';
                if ($blocked) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            fwrite($pipes[0], 'R');
            fflush($pipes[0]);
            $results = [];
            foreach ($children as $index => $pid) {
                $deadline = microtime(true) + 20;
                do {
                    $finished = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($finished === $pid) {
                        unset($children[$index]);
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertSame($pid, $finished, 'Isolated worker did not finish');
                $data = json_decode(file_get_contents($prefix.'-'.$index.'.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('exception', $data, 'Isolated worker raised '.($data['exception'] ?? ''));
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                $results[$index] = $data;
            }
            $this->assertTrue($blocked, 'Second worker must contend on the first worker lock');

            return $results;
        } finally {
            @fwrite($pipes[0], 'R');
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            fclose($pipes[0]);
            fclose($pipes[1]);
            foreach ([0, 1] as $index) {
                @unlink($prefix.'-'.$index.'.json');
            }
            DB::purge();
        }
    }
}
