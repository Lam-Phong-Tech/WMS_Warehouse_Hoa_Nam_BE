<?php

namespace Tests\Unit;

use App\Support\DocumentTimestamp as Time;
use DateTimeImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Warehouse\Http\Middleware\DocumentTimestampResponse;
use Modules\Warehouse\Models\StockDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DocumentTimestampContractTest extends TestCase
{
    #[DataProvider('validTimes')]
    public function test_exact_instant_fraction_null_and_idempotent_serialization(mixed $raw, ?string $expected): void
    {
        config(['document_timestamps.legacy_naive_timezone' => 'Asia/Ho_Chi_Minh']);
        $this->assertSame($expected, Time::utc($raw));
        $this->assertSame($expected, Time::utc(Time::utc($raw)));
    }

    public static function validTimes(): array
    {
        return [
            [null, null],
            ['2026-10-11 21:40:07', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-11 11:02:58', '2026-10-11T04:02:58.000000Z'],
            ['2026-10-09 15:11:50', '2026-10-09T08:11:50.000000Z'],
            ['2026-10-11T21:40:07+07:00', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-11 21:40:07+07', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-11T21:40:07+0700', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-11T14:40:07Z', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-11 14:40:07+00', '2026-10-11T14:40:07.000000Z'],
            ['2026-10-12 00:00:00.000001', '2026-10-11T17:00:00.000001Z'],
            ['2026-10-11T23:59:59.999999-01:00', '2026-10-12T00:59:59.999999Z'],
            ['2026-10-11 21:40:07.1', '2026-10-11T14:40:07.100000Z'],
            ['2026-10-11T21:40:07.123456+07:00', '2026-10-11T14:40:07.123456Z'],
            ['2024-02-29 00:00:00', '2024-02-28T17:00:00.000000Z'],
            [new DateTimeImmutable('2026-10-11T21:40:07.012345+07:00'), '2026-10-11T14:40:07.012345Z'],
        ];
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_input_never_becomes_now_or_zero(mixed $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        Time::utc($raw);
    }

    public static function invalidTimes(): array
    {
        return array_map(fn ($value) => [$value], ['', 'tomorrow', '2026-10-11', 0, [],
            '2026-02-29 12:00:00', '2026-13-11 12:00:00', '2026-10-11 24:00:00',
            '2026-10-11 21:40:07.1234567', '2026-10-11T21:40:07+07:99',
            '2026-10-11T21:40:07+15:00', '2026-10-11T21:40:07-00:00', '2026-10-11 21:40:07 trailing']);
    }

    public function test_storage_convention_is_independent_of_php_and_app_timezone_and_does_not_mutate_model(): void
    {
        $old = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $phpZone) {
                date_default_timezone_set($phpZone);
                config(['app.timezone' => $phpZone, 'document_timestamps.legacy_naive_timezone' => 'Asia/Ho_Chi_Minh']);
                $model = new StockDocument;
                $model->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000001',
                    'created_at' => '2026-10-11 21:40:07.123456', 'updated_at' => null,
                    'doc_date' => '2026-10-11', 'status' => 'DRAFT'], true);
                $before = $model->getAttributes();
                $this->assertSame('2026-10-11T14:40:07.123456Z', $model->toArray()['created_at']);
                $this->assertNull($model->toArray()['updated_at']);
                $this->assertSame($before, $model->getAttributes());
                $this->assertFalse($model->isDirty());
                $this->assertArrayNotHasKey('created_at', $model->makeHidden('created_at')->toArray());
            }
        } finally {
            date_default_timezone_set($old);
        }
    }

    public function test_response_whitelist_keeps_null_dates_missing_fields_receipts_and_errors(): void
    {
        config(['document_timestamps.legacy_naive_timezone' => 'Asia/Ho_Chi_Minh']);
        $middleware = new DocumentTimestampResponse;
        $data = ['created_at' => '2026-10-11 21:40:07', 'updated_at' => null, 'doc_date' => '2026-10-11',
            'receipt' => ['created_at' => '2026-10-11 21:40:07'],
            'p12' => ['timeline' => ['milestones' => ['created' => ['occurred_at' => '2026-10-11 21:40:07']]]]];
        $result = $middleware->handle(Request::create('/read', 'GET'), fn () => response()->json(['success' => true, 'data' => $data]));
        $body = $result->getData(true);
        $this->assertSame('2026-10-11T14:40:07.000000Z', $body['data']['created_at']);
        $this->assertSame($body['data']['created_at'], $body['data']['p12']['timeline']['milestones']['created']['occurred_at']);
        $this->assertSame($data['receipt'], $body['data']['receipt']);
        $this->assertSame('2026-10-11', $body['data']['doc_date']);
        $this->assertNull($body['data']['updated_at']);
        $this->assertArrayNotHasKey('returned_at', $body['data']);
        foreach (['POST', 'PATCH'] as $method) {
            $response = response()->json(['success' => true, 'data' => $data]);
            $before = $response->getContent();
            $this->assertSame($before, $middleware->handle(Request::create('/write', $method), fn () => $response)->getContent());
        }
        $error = response()->json(['success' => false, 'message' => 'denied'], 403);
        $this->assertSame($error, $middleware->handle(Request::create('/read', 'GET'), fn () => $error));
    }
}
