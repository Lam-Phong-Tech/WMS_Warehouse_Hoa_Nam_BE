<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\PublicApiEnvelope;
use App\Support\PublicApiError;
use App\Support\PublicApiErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\PublicQuoteRequest;
use Modules\Warehouse\Models\PublicQuoteRequestItem;
use Modules\Warehouse\Services\PublicCatalogReadModel;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/public/quote-requests — gửi yêu cầu tư vấn tối thiểu.
 *
 * PHẠM VI ĐÓNG. Hợp đồng ghi rõ: "không cần, và không được thêm CRM/ERP,
 * notification, queue, outbox, retry worker, giá/tồn kho/cart/order". Endpoint
 * này vì thế chỉ làm đúng ba việc: xác thực đầu vào, ghi một yêu cầu, trả về
 * {request_id, status}. Không dispatch job, không gửi mail, không bắn event.
 *
 * Đây là endpoint GHI DUY NHẤT của mặt công khai, tức là bề mặt tấn công duy
 * nhất có tác dụng phụ, nên nó có limiter riêng chặt hơn nhóm đọc
 * (throttle:public-quote-request) chứ không dùng chung ngưỡng 120 req/phút.
 *
 * ------------------------------------------------------------------------
 * NHIỀU SẢN PHẨM TRONG MỘT YÊU CẦU — `items[]`
 * ------------------------------------------------------------------------
 *
 * App PV cho khách chọn nhiều sản phẩm rồi bấm gửi MỘT lần, nên hợp đồng có
 * `items[]` chính thức và BE ghi mỗi sản phẩm thành một dòng
 * `public_quote_request_items`. Ba đường vòng phía FE bị loại bỏ có chủ ý:
 *
 *   - Gửi N request: một khách hoá thành N lead rời rạc, không nối lại được.
 *   - Chỉ gửi sản phẩm đầu tiên: N-1 sản phẩm biến mất không dấu vết.
 *   - Nhét danh sách vào `note`: dữ liệu nghiệp vụ chui vào ô tự do đã mã hoá,
 *     không truy vấn được nữa.
 *
 * Hình CŨ (`product_id` ở gốc body) vẫn được nhận nguyên vẹn và được chuẩn hoá
 * thành `items[0]` — bản App PV đang chạy ngoài thị trường không được vỡ vì một
 * lần deploy BE.
 *
 * Việc này KHÔNG biến endpoint thành giỏ hàng: không giá, không tồn, không giữ
 * chỗ, không trạng thái dòng. `quantity` chỉ là con số khách nêu để người tư
 * vấn gọi lại cho đúng.
 */
class PublicQuoteRequestController extends Controller
{
    public function __construct(private readonly PublicCatalogReadModel $readModel) {}

    #[OA\Post(
        path: '/api/v1/public/quote-requests',
        operationId: 'createPublicQuoteRequest',
        summary: 'Storefront — gửi yêu cầu tư vấn (ẩn danh, idempotent, nhiều sản phẩm)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'phone', 'consent', 'privacy_version'],
                properties: [
                    new OA\Property(
                        property: 'items',
                        description: 'Danh sách sản phẩm khách chọn (1..max_items). Bắt buộc, TRỪ khi dùng hình cũ `product_id` ở gốc body.',
                        type: 'array',
                        items: new OA\Items(
                            required: ['product_id'],
                            properties: [
                                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'variant_id', type: 'string', format: 'uuid', nullable: true),
                                new OA\Property(
                                    property: 'quantity',
                                    description: 'Số lượng khách nêu. Bỏ trống nếu khách không nói — null KHÁC 1.',
                                    type: 'integer',
                                    minimum: 1,
                                    nullable: true,
                                ),
                            ],
                            type: 'object',
                        ),
                    ),
                    new OA\Property(
                        property: 'product_id',
                        description: 'HÌNH CŨ, một sản phẩm. Vẫn được nhận; gửi kèm items[] thì phải trùng items[0].product_id.',
                        type: 'string',
                        format: 'uuid',
                        nullable: true,
                    ),
                    new OA\Property(property: 'variant_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'full_name', type: 'string', maxLength: 120, example: 'Nguyễn Văn A'),
                    new OA\Property(property: 'phone', type: 'string', example: '0912345678'),
                    new OA\Property(property: 'province_code', type: 'string', maxLength: 16, nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
                    new OA\Property(property: 'consent', description: 'Phải là true.', type: 'boolean', example: true),
                    new OA\Property(property: 'privacy_version', description: 'Lấy từ GET /api/v1/public/config.', type: 'string', example: '1.0.0'),
                ],
                type: 'object',
            ),
        ),
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
            new OA\Response(response: 404, description: 'PRODUCT_NOT_FOUND — `errors` chỉ đúng dòng items hỏng'),
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
            $created = $this->persist($request, $idempotencyKey, $payloadHash, $payload);
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

        [$items, $usesItemsShape] = $this->parseItems($request, $errors);

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

        return [
            'items' => $this->resolveItems($items, $usesItemsShape),
            'full_name' => $fullName,
            'phone' => $phone,
            'phone_normalized' => $phoneNormalized,
            'province_code' => $provinceCode,
            'note' => $note,
            'privacy_version' => $privacyVersion,
        ];
    }

    /**
     * Đọc lựa chọn sản phẩm ở CẢ HAI hình dạng của hợp đồng và trả về đúng một
     * danh sách, để phần còn lại của controller không phải biết client gửi kiểu
     * nào.
     *
     * Giá trị trả về thứ hai nói client có dùng hình `items[]` hay không — nó
     * chỉ dùng để đặt tên khoá lỗi (`items.2.product_id` với hình mới,
     * `product_id` với hình cũ). App cũ ngoài thị trường switch-case trên đúng
     * chuỗi `product_id`; đổi khoá lỗi của nó là làm vỡ màn hình báo lỗi của
     * một bản build không ai cập nhật được nữa.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function parseItems(Request $request, array &$errors): array
    {
        $raw = $request->input('items');

        // ---------------- Hình CŨ: một sản phẩm ở gốc body ----------------
        if ($raw === null) {
            $productId = $this->stringInput($request, 'product_id');
            $variantId = $this->stringInput($request, 'variant_id');

            if ($productId === null) {
                $errors['product_id'] = ['Vui lòng chọn sản phẩm cần tư vấn.'];
            } elseif (! $this->looksLikeUuid($productId)) {
                $errors['product_id'] = ['product_id không hợp lệ.'];
            }

            if ($variantId !== null && ! $this->looksLikeUuid($variantId)) {
                $errors['variant_id'] = ['variant_id không hợp lệ.'];
            }

            return [
                [['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => null]],
                false,
            ];
        }

        // ---------------- Hình MỚI: items[] ----------------
        $maxItems = (int) config('public_api.quote_request.max_items', 20);

        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors['items'] = ['items phải là mảng danh sách sản phẩm.'];

            return [[], true];
        }

        if ($raw === []) {
            $errors['items'] = ['Vui lòng chọn ít nhất một sản phẩm.'];

            return [[], true];
        }

        if (count($raw) > $maxItems) {
            // Trần này là ranh giới nghiệp vụ chứ không phải giới hạn kỹ thuật:
            // một "yêu cầu tư vấn" 100 dòng không còn là yêu cầu tư vấn nữa.
            $errors['items'] = ["Một yêu cầu tư vấn tối đa {$maxItems} sản phẩm."];

            return [[], true];
        }

        $items = [];
        $seen = [];

        foreach ($raw as $index => $entry) {
            $key = 'items.'.$index;

            if (! is_array($entry)) {
                $errors[$key] = ['Mỗi phần tử của items phải là một đối tượng.'];

                continue;
            }

            $productId = $this->stringValue($entry['product_id'] ?? null);
            $variantId = $this->stringValue($entry['variant_id'] ?? null);

            if ($productId === null) {
                $errors[$key.'.product_id'] = ['Vui lòng chọn sản phẩm cần tư vấn.'];
            } elseif (! $this->looksLikeUuid($productId)) {
                $errors[$key.'.product_id'] = ['product_id không hợp lệ.'];
            } elseif (isset($seen[$productId])) {
                // Gộp hộ hai dòng trùng là tự ý sửa nội dung khách gửi, và khi
                // đó danh sách BE ghi lại không còn khớp danh sách FE gửi đi.
                $errors[$key.'.product_id'] = ['Sản phẩm này đã có ở dòng '.($seen[$productId] + 1).'.'];
            } else {
                $seen[$productId] = $index;
            }

            if ($variantId !== null && ! $this->looksLikeUuid($variantId)) {
                $errors[$key.'.variant_id'] = ['variant_id không hợp lệ.'];
            }

            $items[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $this->quantityValue($entry['quantity'] ?? null, $key.'.quantity', $errors),
            ];
        }

        // FE trong giai đoạn chuyển tiếp có thể gửi KÈM `product_id` cũ để chạy
        // được với cả bản BE chưa có items[]. Chấp nhận, nhưng chỉ khi nó trùng
        // dòng 1: lệch nhau nghĩa là hai chỗ trong FE đang nói hai chuyện khác
        // nhau, và im lặng chọn một bên chính là kiểu sai nghiệp vụ cần chặn.
        $legacyProductId = $this->stringInput($request, 'product_id');
        if ($legacyProductId !== null && ($items[0]['product_id'] ?? null) !== $legacyProductId) {
            $errors['product_id'] = ['Body có cả items[] và product_id nhưng không khớp nhau; chỉ cần gửi items[].'];
        }

        return [$items, true];
    }

    /**
     * Đối chiếu lựa chọn với danh mục công khai rồi gắn ảnh chụp mã/tên sản phẩm.
     *
     * Chạy SAU khi mọi lỗi định dạng đã sạch, vì đây là bước duy nhất đụng DB —
     * không đáng chạy cho một body sai chính tả.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveItems(array $items, bool $usesItemsShape): array
    {
        $snapshots = $this->readModel->visibleProductSnapshots(array_column($items, 'product_id'));

        $missing = [];
        foreach ($items as $index => $item) {
            if (! isset($snapshots[$item['product_id']])) {
                $missing[$this->itemErrorKey($usesItemsShape, $index, 'product_id')] = ['Sản phẩm không còn khả dụng.'];
            }
        }

        if ($missing !== []) {
            // Cùng lý do như GET /products/{slug}: không phân biệt "không tồn
            // tại" với "chưa publish/hết hàng". Nhưng CÓ nói rõ dòng nào hỏng —
            // client vốn đã biết những id nó vừa gửi, và không nói thì FE chỉ
            // còn cách xoá sạch lựa chọn của khách.
            PublicApiError::abort(
                404,
                PublicApiErrorCode::PRODUCT_NOT_FOUND,
                'Không tìm thấy sản phẩm.',
                $missing,
            );
        }

        $variantErrors = [];
        foreach ($items as $index => $item) {
            if ($item['variant_id'] === null) {
                continue;
            }

            if (! $this->readModel->variantBelongsToProduct($item['product_id'], $item['variant_id'])) {
                $variantErrors[$this->itemErrorKey($usesItemsShape, $index, 'variant_id')] = ['Biến thể không thuộc sản phẩm đã chọn.'];
            }
        }

        if ($variantErrors !== []) {
            PublicApiError::abort(
                422,
                PublicApiErrorCode::VALIDATION_FAILED,
                'Dữ liệu gửi lên chưa hợp lệ.',
                $variantErrors,
            );
        }

        foreach ($items as $index => $item) {
            $items[$index]['sku_code'] = $snapshots[$item['product_id']]['code'];
            $items[$index]['product_name'] = $snapshots[$item['product_id']]['name'];
        }

        return $items;
    }

    /**
     * Ghi bảng cha và các dòng sản phẩm trong CÙNG một transaction.
     *
     * Không có transaction thì một lỗi ở bước ghi items để lại một yêu cầu tư
     * vấn rỗng sản phẩm — đúng dạng dữ liệu tệ nhất: trông như hợp lệ, đọc lên
     * thì không biết khách hỏi cái gì.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persist(Request $request, string $idempotencyKey, string $payloadHash, array $payload): PublicQuoteRequest
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $payload['items'];
        $primary = $items[0];

        return DB::transaction(function () use ($request, $idempotencyKey, $payloadHash, $payload, $items, $primary): PublicQuoteRequest {
            $created = PublicQuoteRequest::create([
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                // Bản sao của DÒNG 1, giữ cho dữ liệu và truy vấn cũ chạy tiếp.
                // Nguồn sự thật là quan hệ items(); `item_count` nằm ngay cạnh
                // để người đọc bảng cha thấy ngay còn dòng khác hay không.
                'product_id' => $primary['product_id'],
                'variant_id' => $primary['variant_id'],
                'item_count' => count($items),
                'full_name' => $payload['full_name'],
                'phone' => $payload['phone'],
                // WWHN-SEC-06: `phone_normalized` KHÔNG còn được ghi xuống DB —
                // nó chính là số điện thoại, chỉ bỏ khoảng trắng, nên giữ lại
                // sẽ vô hiệu hoá việc mã hoá `phone`. Tra cứu nay đi qua blind
                // index `phone_lookup` do mutator của model tự sinh (xem
                // PublicQuoteRequest::phone()). Giá trị chuẩn hoá VẪN nằm trong
                // $payload vì `payloadHash()` băm nó — đổi thành phần băm là
                // đổi ngữ nghĩa idempotency của hợp đồng công khai.
                'province_code' => $payload['province_code'],
                'note' => $payload['note'],
                'consent' => true,
                'privacy_version' => $payload['privacy_version'],
                'status' => PublicQuoteRequest::STATUS_RECEIVED,
                'source' => 'PUBLIC_APP',
                'request_ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);

            $now = now();
            $rows = [];

            foreach ($items as $index => $item) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'request_id' => $created->id,
                    'line_no' => $index + 1,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'sku_code_snapshot' => Str::limit((string) $item['sku_code'], 100, ''),
                    'product_name_snapshot' => Str::limit((string) $item['product_name'], 255, ''),
                    'created_at' => $now,
                ];
            }

            PublicQuoteRequestItem::insert($rows);

            return $created;
        });
    }

    /**
     * Băm nội dung nghiệp vụ của yêu cầu để phát hiện "cùng khoá, khác nội dung".
     *
     * `items` CHỈ được đưa vào chuỗi băm khi lựa chọn không diễn đạt nổi bằng
     * cặp `product_id`/`variant_id` cũ. Nhờ vậy một body một-sản-phẩm băm ra
     * ĐÚNG chuỗi như trước khi có `items[]`: lần retry vắt qua thời điểm deploy
     * vẫn replay được kết quả cũ thay vì ăn 409 chỉ vì server đổi cách băm.
     *
     * Ảnh chụp mã/tên sản phẩm KHÔNG nằm trong chuỗi băm: chúng do server tra
     * ra, và danh mục đổi tên giữa hai lần retry sẽ biến một lần gửi lại y hệt
     * thành xung đột.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $payload['items'];

        $canonical = [
            'product_id' => $items[0]['product_id'],
            'variant_id' => $items[0]['variant_id'],
            'full_name' => $payload['full_name'],
            'phone' => $payload['phone'],
            'phone_normalized' => $payload['phone_normalized'],
            'province_code' => $payload['province_code'],
            'note' => $payload['note'],
            'privacy_version' => $payload['privacy_version'],
        ];

        if (count($items) > 1 || $items[0]['quantity'] !== null) {
            $canonical['items'] = array_map(fn (array $item): array => [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'quantity' => $item['quantity'],
            ], $items);
        }

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
        return $this->stringValue($request->input($key));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Số lượng khách nêu. `null` (không gửi / gửi rỗng) KHÁC `1`: "chưa nói"
     * không phải "một cái", và điền hộ 1 là bịa ra con số khách không nói.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function quantityValue(mixed $value, string $errorKey, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $max = (int) config('public_api.quote_request.max_quantity', 9999);

        if (is_bool($value) || ! is_numeric($value) || (float) $value !== floor((float) $value)) {
            $errors[$errorKey] = ['Số lượng phải là số nguyên.'];

            return null;
        }

        $quantity = (int) $value;
        if ($quantity < 1 || $quantity > $max) {
            $errors[$errorKey] = ["Số lượng phải từ 1 đến {$max}."];

            return null;
        }

        return $quantity;
    }

    private function itemErrorKey(bool $usesItemsShape, int $index, string $field): string
    {
        return $usesItemsShape ? "items.{$index}.{$field}" : $field;
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
