<?php

namespace Tests\Feature;

use App\Support\IdempotencyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class Wwhn180IdempotencyAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_hash_is_stable_when_json_key_order_changes(): void
    {
        $first = Request::create('/api/v1/inbound-documents/doc-1/post-receipt', 'POST', [
            'reason' => 'test',
            'meta' => ['b' => 2, 'a' => 1],
        ]);
        $second = Request::create('/api/v1/inbound-documents/doc-1/post-receipt', 'POST', [
            'meta' => ['a' => 1, 'b' => 2],
            'reason' => 'test',
        ]);

        $this->assertSame(
            IdempotencyStore::requestHash($first),
            IdempotencyStore::requestHash($second)
        );
    }

    public function test_same_key_same_payload_replays_exact_snapshot_and_runs_callback_once(): void
    {
        $runs = 0;
        $callback = function () use (&$runs): array {
            $runs++;

            return [200, ['success' => true, 'data' => ['status' => 'received', 'movement_ids' => ['mv-1']]]];
        };

        $first = IdempotencyStore::handle('wwhn180-same', 'post_receipt_doc-1', '0', 'hash-a', $callback);
        $second = IdempotencyStore::handle('wwhn180-same', 'post_receipt_doc-1', '0', 'hash-a', $callback);

        $this->assertSame(1, $runs);
        $this->assertFalse($first[2]);
        $this->assertTrue($second[2]);
        $this->assertSame($first[0], $second[0]);
        $this->assertSame($first[1], $second[1]);
    }

    public function test_same_key_different_payload_returns_409_without_second_callback(): void
    {
        $runs = 0;
        $callback = function () use (&$runs): array {
            $runs++;

            return [200, ['success' => true]];
        };

        IdempotencyStore::handle('wwhn180-diff', 'post_issue_doc-1', '0', 'hash-a', $callback);
        $conflict = IdempotencyStore::handle('wwhn180-diff', 'post_issue_doc-1', '0', 'hash-b', $callback);

        $this->assertSame(1, $runs);
        $this->assertSame(409, $conflict[0]);
        $this->assertSame('IDEMPOTENCY_ERROR', $conflict[1]['error']['code']);
    }

    public function test_business_write_rolls_back_and_lease_is_removed_when_callback_fails(): void
    {
        try {
            IdempotencyStore::handle(
                'wwhn180-rollback',
                'post_receipt_doc-rollback',
                '0',
                'hash-rollback',
                function (): array {
                    DB::table('idempotency_keys')->insert([
                        'idem_key' => 'nested-business-write',
                        'user_id' => '0',
                        'operation' => 'probe',
                        'business_key' => sha1('probe'),
                        'request_hash' => 'probe',
                        'status' => 'done',
                        'response_code' => 200,
                        'response_body' => '{}',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    throw new RuntimeException('Mô phỏng lỗi sau stock write trước response commit.');
                }
            );
            $this->fail('Callback lỗi phải được ném ra ngoài.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mô phỏng lỗi', $exception->getMessage());
        }

        $this->assertDatabaseMissing('idempotency_keys', ['idem_key' => 'nested-business-write']);
        $this->assertDatabaseMissing('idempotency_keys', [
            'idem_key' => 'wwhn180-rollback',
            'operation' => 'post_receipt_doc-rollback',
            'user_id' => '0',
        ]);
    }
}
