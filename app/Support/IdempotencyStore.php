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
     * WWHN-289 (DEV289-P1-02): mã lỗi 409 phải nằm trong đúng danh sách của
     * SRS §8.2 (STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT /
     * DOWNSTREAM_DEPENDENCY_EXISTS). AC-GWT-IDEMP-001 và TC-P1-006/014/030/038
     * gọi đích danh `409 IDEMPOTENCY_CONFLICT` cho tình huống cùng key khác
     * payload hash; FE cũng đã map sẵn mã này
     * (`src/shared/api/wwhn-153-final-signoff.ts`).
     */
    public const CONFLICT_PAYLOAD_DIFFERS = 'IDEMPOTENCY_CONFLICT';

    /** Tranh chấp lease là xung đột đồng thời, không phải sai payload. */
    public const CONFLICT_LEASE_BUSY = 'CONCURRENCY_CONFLICT';

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

            $leaseUntil = self::leaseDeadline();
            $affected = self::query($key, $operation, $userId)
                ->where(function ($query) {
                    $query->where('status', 'failed')
                        ->orWhere(function ($stale) {
                            $stale->where('status', 'processing')
                                ->where(function ($expired) {
                                    $expired->whereNull('locked_until')
                                        ->orWhere('locked_until', '<=', now()->startOfSecond());
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

        $leaseUntil = self::leaseDeadline();

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
        } catch (Throwable $exception) {
            // Chỉ quy về race khi thực sự đã có row cạnh tranh. Lỗi schema/kết nối phải nổi lên.
            if (self::query($key, $operation, $userId)->first() === null) {
                throw $exception;
            }

            return self::resolveAfterLostRace($key, $operation, $userId, $requestHash);
        }

        return self::run($key, $operation, $userId, $requestHash, $leaseUntil, $fn);
    }

    /**
     * Hash v2 dùng JSON canonical + SHA-256. Phần md5 legacy cho phép replay các row đã
     * tạo trước khi nâng cấp mà không biến một retry hợp lệ thành 409 sau deployment.
     * Comparator luôn ưu tiên digest v2; suffix md5 chỉ dùng khi đối chiếu row legacy.
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
        $legacy = md5($request->fullUrl().'|'.$request->getMethod().'|'.json_encode($request->all()));

        return 'v2:'.hash('sha256', $canonical).'|md5:'.$legacy;
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
            return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.', self::CONFLICT_LEASE_BUSY);
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

        return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.', self::CONFLICT_LEASE_BUSY);
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
                    return self::conflict('Lease Idempotency đã được request khác tiếp quản.', self::CONFLICT_LEASE_BUSY);
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
        if ($requestHash === null || $row->request_hash === null) {
            return false;
        }

        $stored = (string) $row->request_hash;
        if (hash_equals($stored, $requestHash)) {
            return false;
        }

        // Hai hash v2 phải được so bằng canonical SHA-256. Suffix MD5 có thể khác khi
        // object key order khác nhau và tuyệt đối không được gây false 409.
        $storedV2 = self::v2Digest($stored);
        $requestV2 = self::v2Digest($requestHash);
        if ($storedV2 !== null && $requestV2 !== null) {
            return ! hash_equals($storedV2, $requestV2);
        }

        // Tương thích row md5 cũ với request hash v2 có đính kèm md5 legacy.
        if (preg_match('/^[a-f0-9]{32}$/', $stored) === 1
            && preg_match('/\|md5:([a-f0-9]{32})$/', $requestHash, $matches) === 1) {
            return ! hash_equals($stored, $matches[1]);
        }

        return true;
    }

    private static function v2Digest(string $hash): ?string
    {
        if (preg_match('/^v2:([a-f0-9]{64})(?:\|md5:[a-f0-9]{32})?$/', $hash, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function leaseExpired(mixed $lockedUntil): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return true;
        }

        return now()->startOfSecond()->greaterThanOrEqualTo(Carbon::parse($lockedUntil)->startOfSecond());
    }

    private static function sameLease(mixed $stored, Carbon $expected): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        return Carbon::parse($stored)->startOfSecond()->equalTo($expected->copy()->startOfSecond());
    }

    private static function leaseDeadline(): Carbon
    {
        return now()->addMinutes(self::LOCK_MINUTES)->startOfSecond();
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => self::canonicalize($item), $value);
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
    private static function conflict(string $message, string $errorCode = self::CONFLICT_PAYLOAD_DIFFERS): array
    {
        return [409, [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => [],
            'error' => ['code' => $errorCode, 'details' => []],
        ], false];
    }
}
