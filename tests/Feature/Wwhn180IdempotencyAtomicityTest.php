<?php

namespace Tests\Feature;

use App\Support\IdempotencyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Http\Controllers\Concerns\InteractsWithStockDocuments;
use Modules\Warehouse\Services\StockDocumentService;
use RuntimeException;
use Tests\TestCase;

class Wwhn180IdempotencyAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantically_equal_payload_with_different_key_order_replays_instead_of_false_conflict(): void
    {
        $firstRequest = Request::create('/api/v1/inbound-documents/doc-1/post-receipt', 'POST', [
            'reason' => 'test',
            'meta' => ['b' => 2, 'a' => 1],
        ]);
        $secondRequest = Request::create('/api/v1/inbound-documents/doc-1/post-receipt', 'POST', [
            'meta' => ['a' => 1, 'b' => 2],
            'reason' => 'test',
        ]);

        $firstHash = IdempotencyStore::requestHash($firstRequest);
        $secondHash = IdempotencyStore::requestHash($secondRequest);

        // Phần legacy MD5 có thể khác do thứ tự key cũ; canonical v2 digest phải giống nhau.
        $this->assertSame(
            explode('|md5:', $firstHash, 2)[0],
            explode('|md5:', $secondHash, 2)[0]
        );

        $runs = 0;
        $callback = function () use (&$runs): array {
            $runs++;

            return [200, ['success' => true, 'data' => ['status' => 'received', 'movement_ids' => ['mv-canonical']]]];
        };

        $first = IdempotencyStore::handle(
            'wwhn180-canonical-order',
            'post_receipt_doc-1',
            '0',
            $firstHash,
            $callback
        );
        $second = IdempotencyStore::handle(
            'wwhn180-canonical-order',
            'post_receipt_doc-1',
            '0',
            $secondHash,
            $callback
        );

        $this->assertSame(1, $runs);
        $this->assertFalse($first[2]);
        $this->assertTrue($second[2]);
        $this->assertSame($first[0], $second[0]);
        $this->assertSame($first[1], $second[1]);
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

    public function test_idempotency_key_is_trimmed_and_accepts_contract_boundary(): void
    {
        $probe = $this->idempotencyKeyProbe();

        $request = Request::create('/probe', 'POST');
        $request->headers->set('Idempotency-Key', '  12345678  ');
        $this->assertSame('12345678', $probe->extract($request));

        $maxRequest = Request::create('/probe', 'POST');
        $maxRequest->headers->set('Idempotency-Key', str_repeat('k', 100));
        $this->assertSame(str_repeat('k', 100), $probe->extract($maxRequest));
    }

    public function test_blank_idempotency_key_returns_428_before_database_write(): void
    {
        $probe = $this->idempotencyKeyProbe();
        $request = Request::create('/probe', 'POST');
        $request->headers->set('Idempotency-Key', '   ');

        $this->assertApiError(
            fn () => $probe->extract($request),
            428,
            'PRECONDITION_REQUIRED'
        );
    }

    public function test_short_or_oversized_idempotency_key_returns_422_before_database_write(): void
    {
        $probe = $this->idempotencyKeyProbe();

        foreach (['short', str_repeat('k', 101)] as $invalidKey) {
            $request = Request::create('/probe', 'POST');
            $request->headers->set('Idempotency-Key', $invalidKey);

            $this->assertApiError(
                fn () => $probe->extract($request),
                422,
                'VALIDATION_ERROR'
            );
        }
    }

    private function idempotencyKeyProbe(): object
    {
        return new class
        {
            use InteractsWithStockDocuments;

            public function extract(Request $request): string
            {
                return $this->requireIdempotencyKey($request);
            }

            protected function docType(): string
            {
                return 'receipt';
            }

            protected function service(): StockDocumentService
            {
                throw new RuntimeException('Không dùng service trong key validation probe.');
            }
        };
    }

    private function assertApiError(callable $callback, int $expectedStatus, string $expectedCode): void
    {
        try {
            $callback();
            $this->fail("Phải trả HTTP {$expectedStatus} {$expectedCode}.");
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($expectedStatus, $response->getStatusCode());
            $this->assertSame($expectedCode, $payload['error']['code'] ?? null);
        }
    }
}
