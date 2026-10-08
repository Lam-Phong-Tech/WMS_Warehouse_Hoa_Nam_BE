<?php

namespace App\Http\Controllers;

use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Support intake only. Never reads account existence or grants reset/login authority. */
final class P14RecoveryController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $this->throttle('submit:'.$request->ip(), (int) config('p14.recovery_ip_per_hour'), 3600);
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if (array_diff(array_keys($body), ['username']) !== [] || $request->query() !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'RECOVERY_UNEXPECTED_FIELD', 'Chỉ gửi tên đăng nhập.');
        }
        $data = Validator::make($body, ['username' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u']])->validate();
        $operation = strtolower(trim((string) $request->header('Idempotency-Key')));
        $proof = (string) $request->header('X-Recovery-Proof');
        if (! Str::isUuid($operation) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $proof)) {
            ApiError::abort(422, 'RECOVERY_IDENTITY_REQUIRED', 'Cần mã yêu cầu và bằng chứng đọc hợp lệ.');
        }
        $username = trim($data['username']);
        $hash = $this->digest($username);
        // Both existing and nonexistent usernames follow the same code and DB path.
        $this->throttle('username:'.$this->digest(mb_strtolower($username)), (int) config('p14.recovery_username_per_hour'), 3600);
        $row = DB::transaction(function () use ($operation, $proof, $username, $hash): object {
            DB::table('p14_recovery_requests')->insertOrIgnore([
                'operation_id' => $operation, 'proof_hash' => $this->digest($proof), 'request_hash' => $hash,
                'username_ciphertext' => Crypt::encryptString($username),
                'received_at' => now()->utc(), 'expires_at' => now()->utc()->addHours((int) config('p14.recovery_retention_hours')),
            ]);
            $row = DB::table('p14_recovery_requests')->where('operation_id', $operation)->lockForUpdate()->first();
            if ($row === null || ! hash_equals($row->proof_hash, $this->digest($proof))) {
                ApiError::abort(404, 'RECOVERY_REQUEST_NOT_FOUND', 'Không tìm thấy yêu cầu có thể đọc.');
            }
            if (! hash_equals($row->request_hash, $hash)) {
                ApiError::abort(409, 'RECOVERY_IDEMPOTENCY_CONFLICT', 'Mã yêu cầu đã được dùng cho nội dung khác.');
            }
            $this->notExpired($row);

            return $row;
        });

        return $this->receipt($row, 202);
    }

    public function show(Request $request, string $operationId): JsonResponse
    {
        $this->enabled();
        $this->throttle('read:'.$request->ip(), (int) config('p14.recovery_read_per_minute'), 60);
        $proof = (string) $request->header('X-Recovery-Proof');
        $row = Str::isUuid($operationId)
            ? DB::table('p14_recovery_requests')->where('operation_id', strtolower($operationId))->first() : null;
        // Unknown ID and wrong/missing capability have the same response.
        $expected = $row?->proof_hash ?? $this->digest('unavailable');
        $matches = hash_equals($expected, $this->digest($proof));
        if ($row === null || ! $matches || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $proof)) {
            ApiError::abort(404, 'RECOVERY_REQUEST_NOT_FOUND', 'Không tìm thấy yêu cầu có thể đọc.');
        }
        if ($request->query() !== []) {
            ApiError::abort(422, 'RECOVERY_UNEXPECTED_FIELD', 'Không gửi tham số truy vấn.');
        }
        $this->notExpired($row);

        return $this->receipt($row, 200);
    }

    private function enabled(): void
    {
        if (! config('p14.recovery_enabled') || ! filter_var(config('p14.support_email'), FILTER_VALIDATE_EMAIL)) {
            ApiError::abort(503, 'RECOVERY_SUPPORT_UNAVAILABLE', 'Kênh hỗ trợ khôi phục chưa khả dụng.');
        }
    }

    private function notExpired(object $row): void
    {
        if (Carbon::parse($row->expires_at)->lte(now())) {
            ApiError::abort(410, 'RECOVERY_RECEIPT_EXPIRED', 'Quyền tra cứu yêu cầu đã hết hạn.');
        }
    }

    private function receipt(object $row, int $status): JsonResponse
    {
        $response = $this->respondSuccess([
            'operation_id' => $row->operation_id, 'state' => 'RECEIVED',
            'received_at' => Carbon::parse($row->received_at)->utc()->toISOString(),
            'expires_at' => Carbon::parse($row->expires_at)->utc()->toISOString(),
            'account_access_granted' => false,
        ], 'Yêu cầu hỗ trợ đã được ghi nhận. Việc khôi phục cần được quản trị viên xác minh.', $status);
        $body = $response->getData(true);
        $body['meta']['contract'] = 'p14-recovery-v1';

        return $response->setData($body)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function throttle(string $key, int $limit, int $seconds): void
    {
        $key = 'p14-recovery:'.$this->digest($key);
        if (! RateLimiter::attempt($key, $limit, static fn (): bool => true, $seconds)) {
            ApiError::abort(429, 'RECOVERY_RATE_LIMITED', 'Có quá nhiều yêu cầu. Vui lòng thử lại sau.');
        }
    }
}
