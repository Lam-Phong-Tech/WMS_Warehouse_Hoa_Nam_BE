<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\PublicApiEnvelope;
use App\Support\PublicApiError;
use App\Support\PublicApiErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\PublicQuoteRequest;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/public/quote-requests — gửi yêu cầu tư vấn tối thiểu.
 *
 * PHẠM VI ĐÓNG. Hợp đồng ghi rõ: "không cần, và không được thêm CRM/ERP,
 * notification, queue, outbox, retry worker, giá/tồn kho/cart/order". Endpoint
 * này vì thế chỉ làm đúng ba việc: xác thực đầu vào, ghi một dòng, trả về
 * {request_id, status}. Không dispatch job, không gửi mail, không bắn event.
 *
 * Đây là endpoint GHI DUY NHẤT của mặt công khai, tức là bề mặt tấn công duy
 * nhất có tác dụng phụ, nên nó có limiter riêng chặt hơn nhóm đọc
 * (throttle:public-quote-request) chứ không dùng chung ngưỡng 120 req/phút.
 */
class PublicQuoteRequestController extends Controller
{
    public function __construct(private readonly PublicCatalogReadModel $readModel) {}

    #[OA\Post(
        path: '/api/v1/public/quote-requests',
        operationId: 'createPublicQuoteRequest',
        summary: 'Storefront — gửi yêu cầu tư vấn (ẩn danh, idempotent)',
        security: [],
        tags: ['13. Public Storefront'],
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                description: 'Chuỗi 16-128 ký tự do client sinh; gửi lại cùng khoá sẽ trả về đúng kết quả cũ.',
                schema: new OA\Schema(type: 'string', minLength: 16, maxLength: 128),
            ),
        ],
        responses: [
            new OA\Response(response: 201, description: '{request_id, status: RECEIVED}'),
            new OA\Response(response: 200, description: 'Đã nhận trước đó với cùng Idempotency-Key'),
            new OA\Response(response: 404, description: 'PRODUCT_NOT_FOUND'),
            new OA\Response(response: 409, description: 'IDEMPOTENCY_CONFLICT — cùng khoá nhưng khác nội dung'),
            new OA\Response(response: 422, description: 'VALIDATION_FAILED / CONSENT_REQUIRED / PRIVACY_VERSION_MISMATCH'),
            new OA\Response(response: 428, description: 'IDEMPOTENCY_KEY_REQUIRED'),
            new OA\Response(response: 429, description: 'RATE_LIMIT_EXCEEDED'),
            new OA\Response(response: 503, description: 'SERVICE_UNAVAILABLE / FEATURE_DISABLED'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->assertEndpointIsOpen();

        $idempotencyKey = $this->idempotencyKey($request);
        $payload = $this->validatedPayload($request);
        $payloadHash = $this->payloadHash($payload);

        // Đường đi phổ biến nhất của một lần retry: khoá đã tồn tại.
        $existing = PublicQuoteRequest::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->respondForExisting($existing, $payloadHash);
        }

        try {
            $created = PublicQuoteRequest::create([
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'product_id' => $payload['product_id'],
                'variant_id' => $payload['variant_id'],
                'full_name' => $payload['full_name'],
                'phone' => $payload['phone'],
                'phone_normalized' => $payload['phone_normalized'],
                'province_code' => $payload['province_code'],
                'note' => $payload['note'],
                'consent' => true,
                'privacy_version' => $payload['privacy_version'],
                'status' => PublicQuoteRequest::STATUS_RECEIVED,
                'source' => 'PUBLIC_APP',
                'request_ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);
        } catch (QueryException $exception) {
            // Hai request cùng khoá chạy song song: cả hai đều thấy "chưa tồn
            // tại" ở trên rồi cùng insert. Ràng buộc UNIQUE là trọng tài, và
            // request thua cuộc đọc lại bản ghi của request thắng thay vì trả
            // 500. Đây chính là lý do idempotency phải nằm ở index chứ không
            // chỉ ở tầng kiểm tra.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = PublicQuoteRequest::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($winner === null) {
                throw $exception;
            }

            return $this->respondForExisting($winner, $payloadHash);
        }

        return PublicApiEnvelope::success(
            ['request_id' => (string) $created->id, 'status' => $created->status],
            'Đã tiếp nhận yêu cầu tư vấn.',
            201,
        );
    }

    // ------------------------------------------------------------------

    /**
     * Bảo trì hoặc tắt cờ tính năng thì CHẶN, vì gửi yêu cầu tư vấn lúc không ai
     * xử lý là hứa hẹn với người dùng một điều không có thật. Các endpoint đọc
     * vẫn phục vụ bình thường.
     */
    private function assertEndpointIsOpen(): void
    {
        if (! (bool) config('public_api.feature_flags.quote_request', true)) {
            PublicApiError::abort(
                503,
                PublicApiErrorCode::FEATURE_DISABLED,
                'Tính năng gửi yêu cầu tư vấn đang tạm tắt.'
            );
        }

        if ((bool) config('public_api.maintenance.enabled', false)) {
            PublicApiError::abort(
                503,
                PublicApiErrorCode::SERVICE_UNAVAILABLE,
                (string) config('public_api.maintenance.message')
            );
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $min = (int) config('public_api.quote_request.idempotency_key_min', 16);
        $max = (int) config('public_api.quote_request.idempotency_key_max', 128);

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            PublicApiError::abort(
                428,
                PublicApiErrorCode::IDEMPOTENCY_KEY_REQUIRED,
                'Thiếu header Idempotency-Key.'
            );
        }

        $length = strlen($key);
        if ($length < $min || $length > $max) {
            PublicApiError::abort(
                400,
                PublicApiErrorCode::IDEMPOTENCY_KEY_INVALID,
                "Idempotency-Key phải dài {$min}-{$max} ký tự."
            );
        }

        // Khoá đi thẳng vào một cột UNIQUE và vào log; giới hạn bảng chữ cái để
        // không phải lo escaping ở mọi nơi nó xuất hiện về sau.
        if (preg_match('/^[A-Za-z0-9\-_.:]+$/', $key) !== 1) {
            PublicApiError::abort(
                400,
                PublicApiErrorCode::IDEMPOTENCY_KEY_INVALID,
                'Idempotency-Key chỉ được chứa chữ, số và các ký tự - _ . :'
            );
        }

        return $key;
    }

    /**
     * Chỉ nhận ĐÚNG các trường trong hợp đồng. Trường lạ bị bỏ qua (không phải
     * lỗi) để FE thêm field mới không làm vỡ app cũ, nhưng cũng không bao giờ
     * lọt xuống DB.
     *
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        $errors = [];

        $productId = $this->stringInput($request, 'product_id');
        if ($productId === null) {
            $errors['product_id'] = ['Vui lòng chọn sản phẩm cần tư vấn.'];
        } elseif (! $this->looksLikeUuid($productId)) {
            $errors['product_id'] = ['product_id không hợp lệ.'];
        }

        $variantId = $this->stringInput($request, 'variant_id');
        if ($variantId !== null && ! $this->looksLikeUuid($variantId)) {
            $errors['variant_id'] = ['variant_id không hợp lệ.'];
        }

        $fullName = $this->stringInput($request, 'full_name');
        $nameMax = (int) config('public_api.quote_request.full_name_max_length', 120);
        if ($fullName === null) {
            $errors['full_name'] = ['Vui lòng nhập họ tên.'];
        } elseif (mb_strlen($fullName) < 2 || mb_strlen($fullName) > $nameMax) {
            $errors['full_name'] = ["Họ tên phải từ 2 đến {$nameMax} ký tự."];
        }

        $phone = $this->stringInput($request, 'phone');
        $phoneNormalized = $phone !== null ? $this->normalizePhone($phone) : null;
        if ($phone === null) {
            $errors['phone'] = ['Vui lòng nhập số điện thoại.'];
        } elseif ($phoneNormalized === null) {
            $errors['phone'] = ['Số điện thoại không hợp lệ.'];
        }

        $note = $this->stringInput($request, 'note');
        $noteMax = (int) config('public_api.quote_request.note_max_length', 1000);
        if ($note !== null && mb_strlen($note) > $noteMax) {
            $errors['note'] = ["Ghi chú tối đa {$noteMax} ký tự."];
        }

        $provinceCode = $this->stringInput($request, 'province_code');
        if ($provinceCode !== null && preg_match('/^[A-Za-z0-9_-]{1,16}$/', $provinceCode) !== 1) {
            $errors['province_code'] = ['province_code không hợp lệ.'];
        }

        if ($errors !== []) {
            PublicApiError::abort(422, PublicApiErrorCode::VALIDATION_FAILED, 'Dữ liệu gửi lên chưa hợp lệ.', $errors);
        }

        // Đồng ý chính sách là điều kiện PHÁP LÝ để lưu dữ liệu cá nhân, nên nó
        // có mã lỗi riêng chứ không lẫn vào lỗi validate chung — FE cần phân
        // biệt để hiện đúng ô checkbox thay vì báo lỗi form chung chung.
        if ($request->boolean('consent') !== true) {
            PublicApiError::abort(
                422,
                PublicApiErrorCode::CONSENT_REQUIRED,
                'Bạn cần đồng ý với chính sách bảo mật trước khi gửi yêu cầu.'
            );
        }

        $privacyVersion = $this->stringInput($request, 'privacy_version');
        $expectedVersion = (string) config('public_api.privacy.version');
        if ($privacyVersion !== $expectedVersion) {
            // Người dùng đang xem một bản chính sách cũ. Chấp nhận sự đồng ý đó
            // là ghi nhận một sự đồng ý KHÔNG đúng với nội dung hiện hành.
            PublicApiError::abort(
                422,
                PublicApiErrorCode::PRIVACY_VERSION_MISMATCH,
                'Chính sách bảo mật đã được cập nhật. Vui lòng tải lại và đồng ý phiên bản mới nhất.',
                ['privacy_version' => [$expectedVersion]]
            );
        }

        if (! $this->readModel->hasVisibleProduct((string) $productId)) {
            // Cùng lý do như GET /products/{slug}: không phân biệt "không tồn
            // tại" với "chưa publish/hết hàng".
            PublicApiError::productNotFound();
        }

        if ($variantId !== null && $variantId !== $productId) {
            PublicApiError::abort(
                422,
                PublicApiErrorCode::VALIDATION_FAILED,
                'Dữ liệu gửi lên chưa hợp lệ.',
                ['variant_id' => ['Biến thể không thuộc sản phẩm đã chọn.']]
            );
        }

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_normalized' => $phoneNormalized,
            'province_code' => $provinceCode,
            'note' => $note,
            'privacy_version' => $privacyVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        $canonical = $payload;
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    private function respondForExisting(PublicQuoteRequest $existing, string $payloadHash): JsonResponse
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            // Cùng khoá, khác nội dung: một trong hai lần gửi là nhầm lẫn của
            // client. Trả lại kết quả cũ sẽ giấu mất lỗi đó.
            PublicApiError::abort(
                409,
                PublicApiErrorCode::IDEMPOTENCY_CONFLICT,
                'Idempotency-Key này đã được dùng cho một yêu cầu khác.'
            );
        }

        return PublicApiEnvelope::success(
            ['request_id' => (string) $existing->id, 'status' => $existing->status],
            'Yêu cầu tư vấn đã được tiếp nhận trước đó.',
            200,
        );
    }

    /**
     * Chuẩn hoá số Việt Nam về dạng 0xxxxxxxxx. Trả null nếu không phải số hợp lệ.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+84')) {
            $digits = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '84') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        $digits = ltrim($digits, '+');

        // Thuê bao di động/cố định Việt Nam sau chuẩn hoá luôn bắt đầu bằng 0 và
        // dài 10-11 chữ số.
        if (preg_match('/^0[0-9]{9,10}$/', $digits) !== 1) {
            return null;
        }

        return $digits;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23505 = unique_violation (PostgreSQL); sqlite trả 23000 kèm thông báo.
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return $sqlState === '23505'
            || ($sqlState === '23000' && str_contains(strtolower($exception->getMessage()), 'unique'));
    }
}
