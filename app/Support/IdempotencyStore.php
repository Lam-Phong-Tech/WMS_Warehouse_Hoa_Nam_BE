<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Request-idempotency dùng chung cho mọi module (bảng `idempotency_keys`).
 *
 * Contract bắt buộc:
 * - khóa logic = (idem_key, operation, user_id);
 * - cùng khóa/cùng payload trả lại đúng response snapshot đã commit;
 * - cùng khóa/khác payload trả 409;
 * - chỉ một lease được phép chạy callback tại một thời điểm;
 * - stock effect và response snapshot phải commit/rollback trong cùng DB transaction;
 * - request lỗi không bị cache để client có thể sửa dữ liệu và thử lại.
 */
class IdempotencyStore
{
    public const TABLE = 'idempotency_keys';

    /** Thời gian tối đa của một lease trước khi được phép takeover. */
    public const LOCK_MINUTES = 5;

    /**
     * @param  callable():array{0:int,1:array}  $fn
     * @return array{0:int,1:array,2:bool} [httpCode, body, isReplay]
     */
    public static function handle(
        ?string $key,
        string $operation,
        ?string $userId,
        ?string $requestHash,
        callable $fn
    ): array {
        if ($key === null || trim($key) === '') {
            [$code, $body] = $fn();

            return [$code, $body, false];
        }

        $key = trim($key);
        $userId = ($userId === null || $userId === '') ? '0' : $userId;
        $row = self::query($key, $operation, $userId)->first();

        if ($row !== null) {
            $terminal = self::resolveExistingRow($row, $requestHash);
            if ($terminal !== null) {
                return $terminal;
            }

            $leaseUntil = now()->addMinutes(self::LOCK_MINUTES);
            $affected = self::query($key, $operation, $userId)
                ->where(function ($query) {
                    $query->where('status', 'failed')
                        ->orWhere(function ($stale) {
                            $stale->where('status', 'processing')
                                ->where(function ($expired) {
                                    $expired->whereNull('locked_until')
                                        ->orWhere('locked_until', '<=', now());
                                });
                        });
                })
                ->update([
                    'status' => 'processing',
                    'request_hash' => $requestHash,
                    'locked_until' => $leaseUntil,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                return self::resolveAfterLostRace($key, $operation, $userId, $requestHash);
            }

            return self::run($key, $operation, $userId, $requestHash, $leaseUntil, $fn);
        }

        $leaseUntil = now()->addMinutes(self::LOCK_MINUTES);

        try {
            DB::table(self::TABLE)->insert([
                'idem_key' => $key,
                'user_id' => $userId,
                'operation' => $operation,
                'business_key' => self::businessKey($key, $operation, $userId),
                'request_hash' => $requestHash,
                'status' => 'processing',
                'locked_until' => $leaseUntil,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            return self::resolveAfterLostRace($key, $operation, $userId, $requestHash);
        }

        return self::run($key, $operation, $userId, $requestHash, $leaseUntil, $fn);
    }

    /**
     * Băm request theo JSON canonical và SHA-256 để thứ tự key không làm thay đổi payload hash.
     */
    public static function requestHash(Request $request): string
    {
        $query = self::canonicalize($request->query());
        $body = self::canonicalize($request->all());
        $canonical = json_encode([
            'method' => strtoupper($request->getMethod()),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $query,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /** @return array{0:int,1:array,2:bool}|null */
    private static function resolveExistingRow(object $row, ?string $requestHash): ?array
    {
        if (self::payloadDiffers($row, $requestHash)) {
            return self::conflict('Khóa Idempotency đã được dùng cho một yêu cầu khác với dữ liệu khác. Vui lòng tạo khóa mới.');
        }

        if ($row->status === 'done') {
            return self::replay($row);
        }

        if ($row->status === 'processing' && ! self::leaseExpired($row->locked_until)) {
            return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.');
        }

        return null;
    }

    /** @return array{0:int,1:array,2:bool} */
    private static function resolveAfterLostRace(
        string $key,
        string $operation,
        string $userId,
        ?string $requestHash
    ): array {
        $other = self::query($key, $operation, $userId)->first();

        if ($other !== null && self::payloadDiffers($other, $requestHash)) {
            return self::conflict('Khóa Idempotency đã được dùng cho một yêu cầu khác với dữ liệu khác. Vui lòng tạo khóa mới.');
        }

        if ($other !== null && $other->status === 'done') {
            return self::replay($other);
        }

        return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.');
    }

    /**
     * Callback và snapshot 2xx nằm trong cùng outer transaction. Các transaction lồng bên
     * trong service dùng savepoint/transaction level của Laravel, nên không tồn tại cửa sổ
     * "stock đã commit nhưng idempotency vẫn processing".
     *
     * @return array{0:int,1:array,2:bool}
     */
    private static function run(
        string $key,
        string $operation,
        string $userId,
        ?string $requestHash,
        Carbon $leaseUntil,
        callable $fn
    ): array {
        try {
            return DB::transaction(function () use ($key, $operation, $userId, $requestHash, $leaseUntil, $fn) {
                $lease = self::query($key, $operation, $userId)->lockForUpdate()->first();

                if ($lease === null) {
                    throw new RuntimeException('Idempotency lease không còn tồn tại trước khi chạy nghiệp vụ.');
                }
                if (self::payloadDiffers($lease, $requestHash)) {
                    return self::conflict('Khóa Idempotency đã được dùng cho một yêu cầu khác với dữ liệu khác. Vui lòng tạo khóa mới.');
                }
                if ($lease->status === 'done') {
                    return self::replay($lease);
                }
                if ($lease->status !== 'processing' || ! self::sameLease($lease->locked_until, $leaseUntil)) {
                    return self::conflict('Lease Idempotency đã được request khác tiếp quản.');
                }

                [$code, $body] = $fn();

                if ($code >= 200 && $code < 300) {
                    $updated = self::query($key, $operation, $userId)
                        ->where('status', 'processing')
                        ->where('locked_until', $leaseUntil)
                        ->update([
                            'status' => 'done',
                            'response_code' => $code,
                            'response_body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            'completed_at' => now(),
                            'locked_until' => null,
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new RuntimeException('Không thể hoàn tất Idempotency snapshot cho đúng lease.');
                    }
                } else {
                    self::deleteOwnedLease($key, $operation, $userId, $leaseUntil);
                }

                return [$code, $body, false];
            }, 3);
        } catch (Throwable $exception) {
            // Chỉ dọn đúng lease của request hiện tại; không được xoá lease đã được takeover.
            self::deleteOwnedLease($key, $operation, $userId, $leaseUntil);
            throw $exception;
        }
    }

    private static function deleteOwnedLease(string $key, string $operation, string $userId, Carbon $leaseUntil): void
    {
        self::query($key, $operation, $userId)
            ->where('status', 'processing')
            ->where('locked_until', $leaseUntil)
            ->delete();
    }

    private static function query(string $key, string $operation, string $userId)
    {
        return DB::table(self::TABLE)
            ->where('idem_key', $key)
            ->where('operation', $operation)
            ->where('user_id', $userId);
    }

    private static function payloadDiffers(object $row, ?string $requestHash): bool
    {
        return $requestHash !== null
            && $row->request_hash !== null
            && ! hash_equals((string) $row->request_hash, $requestHash);
    }

    private static function leaseExpired(mixed $lockedUntil): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return true;
        }

        return now()->greaterThanOrEqualTo(Carbon::parse($lockedUntil));
    }

    private static function sameLease(mixed $stored, Carbon $expected): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        return Carbon::parse($stored)->equalTo($expected);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    public static function businessKey(string $key, string $operation, string $userId): string
    {
        return sha1($operation.'|'.$userId.'|'.$key);
    }

    /** @return array{0:int,1:array,2:true} */
    private static function replay(object $row): array
    {
        return [
            (int) $row->response_code,
            json_decode((string) $row->response_body, true, 512, JSON_THROW_ON_ERROR),
            true,
        ];
    }

    /** @return array{0:int,1:array,2:false} */
    private static function conflict(string $message): array
    {
        return [409, [
            'success' => false,
            'message' => $message,
            'error_code' => 'IDEMPOTENCY_ERROR',
            'errors' => [],
            'error' => ['code' => 'IDEMPOTENCY_ERROR', 'details' => []],
        ], false];
    }
}
