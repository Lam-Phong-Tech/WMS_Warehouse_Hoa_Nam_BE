<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Request-idempotency DÙNG CHUNG cho mọi module (bảng `idempotency_keys`).
 *
 * =====================================================================================
 * CONTRACT — chuẩn duy nhất cho request-idempotency. Mọi action ghi dữ liệu quan trọng
 * BẮT BUỘC dùng class này, không tự dựng bảng/cơ chế riêng (bài học từ ERP-Backend gốc:
 * từng có 3 cơ chế idempotency chồng chéo — middleware cache, bảng module A, bảng module
 * B — gây khó bảo trì và sai lệch hành vi giữa các module).
 * =====================================================================================
 *
 * 1. NGUỒN KHÓA: chỉ header `Idempotency-Key`.
 *
 * 2. KHÓA = (idem_key, operation, user_id). **user_id là BẮT BUỘC** — khóa idempotency là
 *    của riêng từng client/user. Thiếu nó thì user B gửi lại key của user A sẽ nhận
 *    response của A.
 *
 * 3. `operation` phải định danh đúng thao tác + đối tượng, vd `create_inbound_document`,
 *    `post_receipt_12`. Cùng một key dùng cho 2 operation khác nhau là HỢP LỆ.
 *
 * 4. XUNG ĐỘT PAYLOAD: cùng khóa nhưng `request_hash` khác → **409 IDEMPOTENCY_ERROR**,
 *    không được im lặng trả lại response cũ.
 *
 * 5. LOCK TREO: khi bắt đầu xử lý ghi `status='processing'` + `locked_until`. Tiến trình
 *    chết cứng (timeout/OOM/kill) sẽ không chạy được nhánh dọn dẹp; hết `locked_until`
 *    thì request sau ĐƯỢC PHÉP chiếm lại khóa.
 *
 * 6. CHỈ LƯU KẾT QUẢ 2xx. Không cache 4xx: client sửa quyền/dữ liệu rồi gửi lại cùng khóa
 *    phải được xử lý lại, không nhận lại lỗi cũ.
 *
 * 7. Callback trả `[int $httpCode, array $body]`. Không có khóa → chạy thẳng, không lưu.
 */
class IdempotencyStore
{
    public const TABLE = 'idempotency_keys';

    /** Thời gian tối đa cho 1 lần xử lý trước khi khóa được coi là treo và cho chiếm lại. */
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
        if ($key === null || $key === '') {
            [$code, $body] = $fn();

            return [$code, $body, false];
        }

        $userId = ($userId === null || $userId === '') ? '0' : $userId;
        $row = self::query($key, $operation, $userId)->first();

        if ($row) {
            if (self::payloadDiffers($row, $requestHash)) {
                return self::conflict('Khóa Idempotency đã được dùng cho một yêu cầu khác với dữ liệu khác. Vui lòng tạo khóa mới.');
            }

            if ($row->status === 'done') {
                return [(int) $row->response_code, json_decode($row->response_body, true) ?? [], true];
            }

            // 'processing' còn hạn → đang có request khác chạy thật.
            if ($row->status === 'processing' && $row->locked_until !== null && now()->lt($row->locked_until)) {
                return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.');
            }

            // Lock treo (tiến trình trước chết) hoặc 'failed' → chiếm lại khóa và chạy lại.
            self::query($key, $operation, $userId)->update([
                'status' => 'processing',
                'request_hash' => $requestHash,
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
                'updated_at' => now(),
            ]);

            return self::run($key, $operation, $userId, $fn);
        }

        try {
            DB::table(self::TABLE)->insert([
                'idem_key' => $key,
                'user_id' => $userId,
                'operation' => $operation,
                'business_key' => self::businessKey($key, $operation, $userId),
                'request_hash' => $requestHash,
                'status' => 'processing',
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Race: request song song vừa chèn cùng khóa.
            $other = self::query($key, $operation, $userId)->first();

            if ($other && self::payloadDiffers($other, $requestHash)) {
                return self::conflict('Khóa Idempotency đã được dùng cho một yêu cầu khác với dữ liệu khác. Vui lòng tạo khóa mới.');
            }

            if ($other && $other->status === 'done') {
                return [(int) $other->response_code, json_decode($other->response_body, true) ?? [], true];
            }

            return self::conflict('Yêu cầu đang được xử lý, vui lòng không gửi trùng lặp.');
        }

        return self::run($key, $operation, $userId, $fn);
    }

    /** Băm payload — dùng chung công thức trong toàn bộ hệ thống. */
    public static function requestHash(Request $request): string
    {
        return md5($request->fullUrl().'|'.$request->getMethod().'|'.json_encode($request->all()));
    }

    /** @return array{0:int,1:array,2:bool} */
    private static function run(string $key, string $operation, string $userId, callable $fn): array
    {
        try {
            [$code, $body] = $fn();
        } catch (\Throwable $e) {
            self::query($key, $operation, $userId)->delete();
            throw $e;
        }

        // Chỉ lưu 2xx. Lỗi 4xx phải cho gọi lại bằng cùng khóa.
        if ($code >= 200 && $code < 300) {
            self::query($key, $operation, $userId)->update([
                'status' => 'done',
                'response_code' => $code,
                'response_body' => json_encode($body, JSON_UNESCAPED_UNICODE),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            self::query($key, $operation, $userId)->delete();
        }

        return [$code, $body, false];
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
            && $row->request_hash !== $requestHash;
    }

    /**
     * `business_key` là cột unique có sẵn của bảng — công thức DUY NHẤT cho mọi module.
     * Băm cả user vào để 2 user cùng key+operation không sinh business_key trùng nhau.
     */
    public static function businessKey(string $key, string $operation, string $userId): string
    {
        return sha1($operation.'|'.$userId.'|'.$key);
    }

    /** @return array{0:int,1:array,2:bool} */
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
