<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\BoxCodeParser;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

/**
 * Local-staging boundary cho màn "Xuất linh kiện" của Mini App (Flow 04).
 *
 * Cùng hình dạng với inbound/outbound staging: pha camera nằm LOCAL trên máy,
 * backend chỉ cung cấp
 *  1) `resolve-code` — tiền kiểm thuần đọc từng mã vừa quét; và
 *  2) `record` — một lệnh nguyên tử dựng phiếu + dòng + toàn bộ bằng chứng quét,
 *     và (mặc định) Post luôn để trừ tồn, đúng một nút "Xác nhận xuất linh kiện".
 *
 * Vì sao cần lớp này: bề mặt canonical (`POST /warranty-component-issues`) bắt
 * client khai `lines[].sku_id` + `required_qty` TRƯỚC khi quét — trong khi nghiệp
 * vụ là quét trước, hệ thống tự nhận ra linh kiện gì và bao nhiêu. Ở đây SKU và số
 * lượng đều do server suy ra từ mã quét; client chỉ gửi `raw_code` (+ số lượng đếm
 * tay cho hộp).
 *
 * Controller này KHÔNG có nghiệp vụ riêng: mọi thay đổi dữ liệu đều đi qua
 * CurrentBaselineComponentFlowController (store/scan/post) nên guard trạng thái
 * hồ sơ bảo hành, XOR item/hộp, chống quét trùng, If-Match và idempotency chỉ tồn
 * tại một bản.
 */
class MiniAppComponentIssueStagingController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /** Máy quét đọc: TTL ngắn nhất, bám `stock:version` như các adapter Mini App khác. */
    private const CACHE_VERSION_NS = 'stock:version';

    /** Trần số dòng quét local của một lần xuất linh kiện. */
    private const MAX_BATCH_ITEMS = 500;

    /**
     * Trạng thái container còn mang tồn — giữ đồng bộ với
     * ComponentContainerSubledgerService::STOCK_BEARING_BOX_STATUSES (private ở đó).
     */
    private const STOCK_BEARING_BOX_STATUSES = ['IN_STOCK', 'PARTIAL', 'RECEIVED_PENDING_PUTAWAY', 'STORED'];

    private const RECORD_FIELDS = [
        'warehouse_id',
        'warranty_case_id',
        'component_issue_no',
        'document_date',
        'note',
        'post_immediately',
        'items',
    ];

    private const RECORD_ITEM_FIELDS = [
        'raw_code',
        'quantity',
        'scan_source',
        // Field client chép nguyên từ resolve-code về row local rồi gửi lại. Được
        // NHẬN cho đỡ phải bóc tách, nhưng KHÔNG tham gia quyết định: raw_code luôn
        // là nguồn sự thật và BE resolve lại từ đầu.
        'sku_id',
        'sku_code',
        'sku_name',
        'item_id',
        'container_id',
        'item_type',
        'requires_quantity',
        'box_code',
        'available_qty',
    ];

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly CurrentBaselineComponentFlowController $flow,
    ) {}

    #[OA\Post(
        path: '/api/v1/mini-app/warranty-component-issues/resolve-code',
        operationId: 'miniAppComponentIssueResolveCode',
        summary: 'Tiền kiểm một mã linh kiện vừa quét cho màn xuất linh kiện bảo hành',
        description: 'Thuần ĐỌC: không tạo phiếu, không tạo bằng chứng quét, không đụng tồn. Mã hộp `BOX-<SKU>-<số hộp>` trả `requires_quantity=true` + `available_qty` để Mini App hiện ô nhập số lượng; mã dán trên từng linh kiện trả `requires_quantity=false` (mỗi lần quét là +1). '
            .'Luôn trả HTTP 200 kể cả khi mã không dùng được — client phải đọc `resolved` + `eligible_for_issue` (bí danh của `eligible_for_component_issue`) chứ không bắt lỗi theo status code, và kiểm thêm `case_allows_issue` cho điều kiện hồ sơ bảo hành. '
            .'Gọi được ở 4 đường dẫn tương đương: `/api/v1/mini-app/warranty-component-issues/resolve-code`, `/api/v1/mini-app/component-issue-documents/resolve-code`, `/api/v1/component-issue-documents/resolve-code` và bí danh Annex C tương ứng.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'raw_code'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'raw_code', type: 'string', description: 'Payload thô của camera/máy quét: QR composite, mã vật lý hoặc mã hộp.', example: 'BOX-HN-COMP-BASE-003-0001'),
                    new OA\Property(property: 'warranty_case_id', type: 'string', format: 'uuid', nullable: true, description: 'Gửi kèm để BE trả luôn trạng thái hồ sơ và cờ `case_allows_issue`.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã resolve mã; stock_effect=NONE; chưa ghi gì vào WMS.'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.component_issue hoặc ngoài warehouse data scope'),
            new OA\Response(response: 422, description: 'Kho không ACTIVE hoặc payload sai định dạng'),
        ]
    )]
    public function resolveCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'warranty_case_id' => ['nullable', 'uuid', 'exists:warranty_case,warranty_case_id'],
        ]);

        $warehouseId = (string) $validated['warehouse_id'];
        $this->assertActiveWarehouse($warehouseId);

        $rawCode = trim((string) $validated['raw_code']);
        $caseId = $validated['warranty_case_id'] ?? null;

        // Endpoint POST nhưng thuần ĐỌC nên `raw_code`/`warranty_case_id` nằm trong
        // body, phải đưa vào khoá cache thủ công. TTL 'stock_short' (20s) + bám
        // `stock:version`: item_status và subledger hộp đều đổi qua mutation tồn kho
        // (đã bump), còn trạng thái hồ sơ đổi qua adapter warranty (cũng bump).
        $payload = $this->cachedReadPayload(
            'miniapp:component-issue:resolve-code',
            ['warehouse_id' => $warehouseId, 'raw_code' => $rawCode, 'warranty_case_id' => (string) $caseId],
            fn (): array => $this->buildResolvePayload($rawCode, $warehouseId, $caseId),
            'stock_short',
            self::CACHE_VERSION_NS,
        );

        return $this->respondSuccess($payload, $payload['eligible_for_component_issue']
            ? 'Mã hợp lệ để đưa vào danh sách xuất linh kiện; chưa ghi nhận vào WMS.'
            : 'Đã resolve mã nhưng chưa đủ điều kiện xuất linh kiện; chưa ghi nhận vào WMS.');
    }

    #[OA\Post(
        path: '/api/v1/mini-app/warranty-component-issues/record',
        operationId: 'miniAppComponentIssueRecord',
        summary: 'Xác nhận xuất linh kiện từ danh sách đã quét (một lệnh nguyên tử)',
        description: 'Nhận nguyên danh sách mã đã quét local, tự gom theo SKU để dựng phiếu + dòng + bằng chứng quét, rồi Post trừ tồn (`post_immediately=false` để dừng ở bước chờ Post). Bất kỳ dòng nào sai là rollback toàn bộ. Bắt buộc Idempotency-Key.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'warranty_case_id', 'items'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warranty_case_id', type: 'string', format: 'uuid', description: 'Hồ sơ phải đang ở Đang kiểm tra (CHECKING) hoặc Đang sửa chữa (REPAIRING).'),
                    new OA\Property(property: 'component_issue_no', type: 'string', maxLength: 80, nullable: true),
                    new OA\Property(property: 'document_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
                    new OA\Property(property: 'post_immediately', type: 'boolean', nullable: true, default: true, description: 'true (mặc định): xuất kho luôn. false: dừng ở phiếu đã quét đủ, chờ Post riêng.'),
                    new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(
                        required: ['raw_code'],
                        properties: [
                            new OA\Property(property: 'raw_code', type: 'string', example: 'BOX-HN-COMP-BASE-003-0001'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, nullable: true, description: 'Bắt buộc với mã hộp; mã dán trên linh kiện thì bỏ trống hoặc bằng 1.'),
                            new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'], nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Đã xuất linh kiện (hoặc dựng xong phiếu chờ Post khi post_immediately=false).'),
            new OA\Response(response: 409, description: 'IDEMPOTENCY_CONFLICT hoặc xung đột trạng thái/đồng thời'),
            new OA\Response(response: 422, description: 'WARRANTY_CASE_STATUS_NOT_ALLOWED, COMPONENT_SKU_REQUIRED, BOX_QUANTITY_REQUIRED, COMPONENT_BOX_INSUFFICIENT, …'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key'),
        ]
    )]
    public function record(Request $request): JsonResponse
    {
        $this->assertRecordPayloadShape($request);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'warranty_case_id' => ['required', 'uuid', 'exists:warranty_case,warranty_case_id'],
            'component_issue_no' => ['nullable', 'string', 'max:80'],
            'document_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'note' => ['nullable', 'string', 'max:2000'],
            'post_immediately' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.raw_code' => ['required', 'string', 'min:1', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'items.*.scan_source' => ['nullable', 'string', 'in:CAMERA,SCANNER,HARDWARE,MANUAL'],
        ]);

        $warehouseId = (string) $validated['warehouse_id'];
        $this->assertActiveWarehouse($warehouseId);

        $postImmediately = ! array_key_exists('post_immediately', $validated) || (bool) $validated['post_immediately'];
        $actorId = $this->actorId();
        $idempotencyKey = IdempotencyKeyGuard::require($request);
        $requestHash = IdempotencyStore::requestHash($request);

        [$code, $body, $replayed] = IdempotencyStore::handle(
            $idempotencyKey,
            'mini_app_component_issue_record',
            $actorId,
            $requestHash,
            function () use ($validated, $request, $actorId, $idempotencyKey, $warehouseId, $postImmediately): array {
                $result = DB::transaction(function () use ($validated, $request, $actorId, $idempotencyKey, $warehouseId, $postImmediately): array {
                    $prepared = $this->prepareBatch((array) $validated['items']);

                    // Bước 1 — dựng phiếu DRAFT. store() là nơi kiểm trạng thái hồ sơ
                    // bảo hành (CHECKING/REPAIRING), warehouse ACTIVE và SKU COMPONENT;
                    // không nhân bản guard ở đây.
                    $createResponse = $this->flow->store($this->childRequest($request, [
                        'component_issue_no' => $validated['component_issue_no'] ?? null,
                        'warehouse_id' => $warehouseId,
                        'warranty_case_id' => (string) $validated['warranty_case_id'],
                        'document_date' => $validated['document_date'] ?? null,
                        'purpose' => 'WARRANTY',
                        'note' => $validated['note'] ?? null,
                        'lines' => $prepared['lines'],
                    ]));
                    $createBody = $createResponse->getData(true);
                    $document = $createBody['data']['document'] ?? null;
                    if ($createResponse->getStatusCode() !== 201 || ! is_array($document) || empty($document['id'])) {
                        ApiError::abort(409, 'COMPONENT_ISSUE_CREATE_FAILED', 'Không tạo được phiếu xuất linh kiện từ danh sách quét; toàn bộ transaction sẽ rollback.');
                    }
                    $documentId = (string) $document['id'];
                    $version = (int) ($document['version'] ?? 1);

                    // Bước 2 — phát lại từng mã qua ĐÚNG handler scan canonical: nó
                    // resolve lại danh tính, kiểm IN_STOCK/hộp đủ số, chống quét trùng
                    // và ghi scan_event làm bằng chứng.
                    $lineIdBySku = $this->lineIdBySku($documentId);
                    $scanned = [];
                    foreach ($prepared['rows'] as $index => $row) {
                        $lineId = $lineIdBySku[$row['sku_id']] ?? null;
                        if ($lineId === null) {
                            // WWHN-267: một mã một HTTP status — COMPONENT_LINE_NOT_FOUND là 404 như
                            // CurrentBaselineComponentFlowController; transaction vẫn rollback toàn bộ.
                            ApiError::abort(404, 'COMPONENT_LINE_NOT_FOUND', 'Không tìm thấy dòng phiếu tương ứng SKU vừa quét.', ['row_no' => $index + 1]);
                        }

                        $scanResponse = $this->flow->scan($this->childRequest($request, [
                            'line_id' => $lineId,
                            'code_value' => $row['raw_code'],
                            'quantity' => $row['quantity'],
                        ], ['If-Match' => '"'.$version.'"']), $documentId);
                        $scanData = $scanResponse->getData(true)['data'] ?? null;
                        if ($scanResponse->getStatusCode() !== 200 || ! is_array($scanData)) {
                            ApiError::abort(409, 'ATOMIC_COMPONENT_SCAN_FAILED', 'Không ghi được toàn bộ danh sách quét; transaction sẽ rollback.', ['row_no' => $index + 1]);
                        }

                        $version = (int) ($scanData['version'] ?? ($version + 1));
                        $scanned[] = [
                            'row_no' => $index + 1,
                            'raw_code' => $row['raw_code'],
                            'item_type' => $row['item_type'],
                            'sku_id' => $row['sku_id'],
                            'sku_code' => $row['sku_code'],
                            'sku_name' => $row['sku_name'],
                            'item_id' => $scanData['item_id'] ?? null,
                            'container_id' => $scanData['container_id'] ?? null,
                            'quantity' => $row['quantity'],
                            'scan_source' => $row['scan_source'],
                            'scan_event_id' => $scanData['scan_event_id'] ?? null,
                        ];
                    }

                    // Bước 3 — "Xác nhận xuất linh kiện" = Post. Đây là hành động DUY
                    // NHẤT trừ tồn trong cả luồng này.
                    $movementIds = [];
                    $status = 'SCANNING';
                    if ($postImmediately) {
                        $postKey = 'ma-ci-post-'.substr(hash('sha256', $actorId.'|'.$idempotencyKey), 0, 48);
                        $postResponse = $this->flow->post($this->childRequest($request, [], [
                            'If-Match' => '"'.$version.'"',
                            'Idempotency-Key' => $postKey,
                        ]), $documentId);
                        $postData = $postResponse->getData(true)['data'] ?? null;
                        if ($postResponse->getStatusCode() !== 200 || ! is_array($postData)) {
                            ApiError::abort(409, 'ATOMIC_COMPONENT_POST_FAILED', 'Không hoàn tất được bước xuất linh kiện; transaction sẽ rollback.');
                        }
                        $movementIds = (array) ($postData['movement_ids'] ?? []);
                        $version = (int) ($postData['version'] ?? ($version + 1));
                        $status = (string) ($postData['status'] ?? 'POSTED');
                    }

                    $caseStatus = (string) DB::table('warranty_case')
                        ->where('warranty_case_id', $validated['warranty_case_id'])
                        ->value('status');

                    AuditLogger::record(
                        action: 'MINI_APP_RECORD_COMPONENT_ISSUE',
                        objectType: 'component_issue_document',
                        objectId: $documentId,
                        actorId: $actorId,
                        requestId: $request->attributes->get('request_id'),
                        afterData: [
                            'status' => $status,
                            'warranty_case_id' => (string) $validated['warranty_case_id'],
                            'warranty_case_status' => $caseStatus,
                            'scan_evidence_count' => count($scanned),
                            'stock_effect' => $postImmediately ? 'DECREASE' : 'NONE',
                            'version' => $version,
                        ],
                        idempotencyKey: $idempotencyKey,
                    );
                    DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));

                    return [
                        'component_issue_document_id' => $documentId,
                        'component_issue_no' => (string) ($document['component_issue_no'] ?? ''),
                        'warehouse_id' => $warehouseId,
                        'warranty_case_id' => (string) $validated['warranty_case_id'],
                        'warranty_case_status' => $caseStatus,
                        'status' => $status,
                        'version' => $version,
                        'posted' => $postImmediately,
                        'stock_effect' => $postImmediately ? 'DECREASE' : 'NONE',
                        'movement_created' => $movementIds !== [],
                        'movement_ids' => array_values($movementIds),
                        'scan_evidence_created' => true,
                        'scan_evidence_count' => count($scanned),
                        'lines' => $this->linesSummary($documentId),
                        'items' => $scanned,
                    ];
                }, 3);

                return [201, $this->envelope(
                    $postImmediately
                        ? 'Đã xuất linh kiện cho hồ sơ bảo hành; tồn kho đã trừ (stock_effect=DECREASE).'
                        : 'Đã dựng phiếu xuất linh kiện đã quét đủ; chưa trừ tồn (stock_effect=NONE).',
                    $result
                )];
            }
        );

        $body = IdempotentEnvelope::withReplayFlag($body, $replayed);
        $response = response()->json($body, $code)->header('Idempotent-Replay', $replayed ? 'true' : 'false');
        $version = is_array($body) ? ($body['data']['version'] ?? null) : null;

        return $version !== null ? $response->header('ETag', '"'.$version.'"') : $response;
    }

    /**
     * Resolve + kiểm tra trước toàn bộ danh sách, gom theo SKU thành `lines`.
     *
     * Hai thứ chỉ phát hiện được ở tầng danh sách (scan từng dòng không thấy):
     *  - cùng một hiện vật serial bị quét hai lần trong CÙNG batch;
     *  - nhiều dòng rút cùng một hộp, tổng vượt tồn của hộp đó.
     * Bắt sớm ở đây để lỗi chỉ đúng dòng nào sai, thay vì rollback với thông điệp
     * mơ hồ từ tận bước Post.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{rows: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>}
     */
    private function prepareBatch(array $items): array
    {
        $rows = [];
        $skuQty = [];
        $seenItems = [];
        $boxQty = [];

        foreach (array_values($items) as $index => $item) {
            $rowNo = $index + 1;
            $rawCode = trim((string) $item['raw_code']);
            $resolved = $this->resolveComponentCode($rawCode);

            if (! $resolved['eligible_for_component_issue']) {
                ApiError::abort(422, (string) $resolved['eligibility_code'], (string) $resolved['eligibility_message'], [
                    'row_no' => $rowNo,
                    'raw_code' => $rawCode,
                ]);
            }

            $declaredQty = array_key_exists('quantity', $item) && $item['quantity'] !== null ? (int) $item['quantity'] : null;
            if ($resolved['item_type'] === 'BOX') {
                if ($declaredQty === null) {
                    ApiError::abort(422, 'BOX_QUANTITY_REQUIRED', 'Mã hộp phải kèm số lượng linh kiện cần xuất.', [
                        'row_no' => $rowNo,
                        'raw_code' => $rawCode,
                        'available_qty' => $resolved['available_qty'],
                    ]);
                }
                $containerId = (string) $resolved['container_id'];
                $boxQty[$containerId] = ($boxQty[$containerId] ?? 0) + $declaredQty;
                if ($boxQty[$containerId] > (int) $resolved['available_qty']) {
                    ApiError::abort(422, 'COMPONENT_BOX_INSUFFICIENT', 'Tổng số lượng rút từ hộp vượt quá tồn của hộp.', [
                        'row_no' => $rowNo,
                        'container_id' => $containerId,
                        'requested_qty' => $boxQty[$containerId],
                        'available_qty' => (int) $resolved['available_qty'],
                    ]);
                }
            } else {
                if ($declaredQty !== null && $declaredQty !== 1) {
                    ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Mã dán trên từng linh kiện chỉ nhận số lượng = 1.', [
                        'row_no' => $rowNo,
                        'raw_code' => $rawCode,
                    ]);
                }
                $declaredQty = 1;
                $itemId = (string) $resolved['item_id'];
                if (isset($seenItems[$itemId])) {
                    ApiError::abort(409, 'DUPLICATE_COMPONENT_ITEM', 'Cùng một linh kiện bị quét hai lần trong danh sách.', [
                        'row_no' => $rowNo,
                        'first_row_no' => $seenItems[$itemId],
                        'raw_code' => $rawCode,
                    ]);
                }
                $seenItems[$itemId] = $rowNo;
            }

            $skuId = (string) $resolved['sku_id'];
            $skuQty[$skuId] = ($skuQty[$skuId] ?? 0) + $declaredQty;
            $rows[] = [
                'raw_code' => $rawCode,
                'quantity' => $declaredQty,
                'scan_source' => (string) ($item['scan_source'] ?? 'CAMERA'),
                'item_type' => (string) $resolved['item_type'],
                'sku_id' => $skuId,
                'sku_code' => $resolved['sku_code'],
                'sku_name' => $resolved['sku_name'],
            ];
        }

        $lines = [];
        foreach ($skuQty as $skuId => $qty) {
            $lines[] = ['sku_id' => $skuId, 'required_qty' => $qty];
        }

        return ['rows' => $rows, 'lines' => $lines];
    }

    /** @return array<string, string> sku_id => line_id */
    private function lineIdBySku(string $documentId): array
    {
        return DB::table('component_issue_lines')
            ->where('component_issue_document_id', $documentId)
            ->orderBy('line_no')
            ->get(['id', 'sku_id'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->sku_id => (string) $row->id])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function linesSummary(string $documentId): array
    {
        return DB::table('component_issue_lines as cl')
            ->leftJoin('sku as s', 's.sku_id', '=', 'cl.sku_id')
            ->where('cl.component_issue_document_id', $documentId)
            ->orderBy('cl.line_no')
            ->get(['cl.id', 'cl.line_no', 'cl.sku_id', 'cl.required_qty', 'cl.scanned_qty', 's.sku_code', 's.sku_name'])
            ->map(fn (object $row): array => [
                'line_id' => (string) $row->id,
                'line_no' => (int) $row->line_no,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => $row->sku_code !== null ? (string) $row->sku_code : null,
                'sku_name' => $row->sku_name !== null ? (string) $row->sku_name : null,
                'required_qty' => (int) $row->required_qty,
                'scanned_qty' => (int) $row->scanned_qty,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function buildResolvePayload(string $rawCode, string $warehouseId, ?string $caseId): array
    {
        $resolved = $this->resolveComponentCode($rawCode);
        $case = $caseId !== null
            ? DB::table('warranty_case')->where('warranty_case_id', $caseId)->first()
            : null;
        $caseStatus = $case !== null ? strtoupper((string) $case->status) : null;
        $caseAllows = $caseStatus !== null
            && in_array($caseStatus, CurrentBaselineComponentFlowController::CASE_STATUSES_ALLOWING_ISSUE, true);

        return [
            ...$resolved,
            // Bí danh theo hợp đồng client Mini App. CÙNG GIÁ TRỊ với sku_id /
            // sku_code / eligible_for_component_issue, không mang nghĩa khác. Gắn
            // ở đây (chứ không ở itemPayload/containerPayload/notResolved) để cả
            // ba nhánh resolve luôn có đủ, khỏi lệch nhau về sau.
            //
            // Giữ luôn tên gốc: `sku_id`/`sku_code` là quy ước dùng chung của cả
            // 5 endpoint resolve-code (inbound, outbound, nfc, warranty và cái
            // này), bỏ riêng một chỗ là làm Mini App tự mâu thuẫn với chính nó.
            //
            // LƯU Ý cho client: `eligible_for_issue` chỉ nói về MÃ VỪA QUÉT (hộp
            // /linh kiện có xuất được không). Điều kiện hồ sơ bảo hành nằm ở
            // `case_allows_issue` ngay dưới — phải kiểm CẢ HAI trước khi cho quét.
            'component_sku_id' => $resolved['sku_id'],
            'component_sku_code' => $resolved['sku_code'],
            'eligible_for_issue' => $resolved['eligible_for_component_issue'],
            'raw_code' => $rawCode,
            'warehouse_id' => $warehouseId,
            'warranty_case_id' => $caseId,
            'warranty_case_status' => $caseStatus,
            'case_allows_issue' => $caseId !== null ? $caseAllows : null,
            'allowed_case_statuses' => CurrentBaselineComponentFlowController::CASE_STATUSES_ALLOWING_ISSUE,
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'scan_evidence_created' => false,
            'wms_document_created' => false,
        ];
    }

    /**
     * Nhận diện mã theo ĐÚNG thứ tự mà CurrentBaselineComponentFlowController
     * ::resolveTarget() dùng: physical_codes trước (QR composite được parse ra
     * item_unique), rớt mới tra containers.container_code cho mã hộp.
     *
     * Bắt buộc giống hệt: lệch một bước là có mã "resolve-code báo xanh nhưng quét
     * vào lại 422", đúng lớp lỗi khó chịu nhất với người đứng quét.
     *
     * @return array<string, mixed>
     */
    private function resolveComponentCode(string $rawCode): array
    {
        $parsed = $this->parser->parse($rawCode);
        $lookup = $parsed->isComposite()
            ? (string) $parsed->itemUnique
            : (string) $parsed->normalizedCodeValue;

        $physical = DB::table('physical_codes')
            ->where('code_value', $lookup)
            ->where('status', 'ACTIVE')
            ->first();

        if ($physical !== null) {
            $itemId = $physical->item_id ?? (($physical->assigned_object_type ?? null) === 'ITEM' ? $physical->assigned_object_id : null);
            $containerId = $physical->container_id ?? (($physical->assigned_object_type ?? null) === 'CONTAINER' ? $physical->assigned_object_id : null);
            if ($itemId !== null) {
                return $this->itemPayload((string) $itemId, $lookup);
            }
            if ($containerId !== null) {
                return $this->containerPayload((string) $containerId, $lookup);
            }

            return $this->notResolved($lookup, 'PHYSICAL_CODE_UNASSIGNED', 'Mã vật lý chưa gắn với linh kiện hay hộp nào.');
        }

        $container = DB::table('containers')
            ->where('container_code', $lookup)
            ->where('container_type', 'BOX')
            ->first();
        if ($container !== null) {
            return $this->containerPayload((string) $container->container_id, $lookup);
        }

        return $this->unresolvedBoxReason($lookup)
            ?? $this->notResolved($lookup, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR/RFID không tồn tại hoặc không ACTIVE.');
    }

    /**
     * Mã có DẠNG mã hộp `BOX-<SKU>-<số hộp>` nhưng không tra ra hộp nào: nói rõ
     * hỏng ở đâu thay vì trả "Barcode/QR/RFID không tồn tại".
     *
     * Câu chung chung đã tốn hai vòng trao đổi với đội Mini App (họ lần lượt kết
     * luận "BE thiếu tính năng tạo container" rồi "BE chặn nhầm hộp hợp lệ", trong
     * khi thực tế chỉ là mã thử nghiệm chưa từng nhập kho). Người đứng quét cũng
     * cần đúng thông tin này: thiếu SKU, nhầm loại SKU, hay hộp chưa nhập kho là
     * ba việc phải xử lý khác nhau.
     *
     * @return array<string, mixed>|null null nếu mã không phải dạng mã hộp
     */
    private function unresolvedBoxReason(string $lookup): ?array
    {
        $box = app(BoxCodeParser::class)->parse($lookup);
        if ($box === null) {
            return null;
        }

        $skuCode = (string) $box['sku_code'];
        $sku = DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [strtoupper($skuCode)])->first();

        if ($sku === null) {
            return $this->notResolved($lookup, 'BOX_SKU_NOT_FOUND',
                "Mã hộp trỏ tới SKU [{$skuCode}] chưa có trong danh mục. Kiểm tra lại mã in trên hộp.");
        }
        if (strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
            return $this->notResolved($lookup, 'BOX_SKU_NOT_COMPONENT',
                "SKU [{$skuCode}] là ".strtoupper((string) $sku->sku_type).', không phải linh kiện — mã hộp linh kiện chỉ dùng cho SKU COMPONENT.');
        }
        if (strtoupper((string) $sku->status) !== 'ACTIVE') {
            return $this->notResolved($lookup, 'BOX_SKU_INACTIVE',
                "SKU linh kiện [{$skuCode}] đang không ACTIVE.");
        }

        // SKU đúng nhưng chưa có container: hộp chưa từng đi qua Post Receipt.
        // Xuất kho KHÔNG tự tạo hộp — tồn chỉ tăng ở nhập kho.
        return $this->notResolved($lookup, 'BOX_NOT_RECEIVED',
            "Hộp [{$lookup}] chưa được nhập kho nên không có tồn để xuất. Nhập hộp qua phiếu nhập trước.");
    }

    /** @return array<string, mixed> */
    private function itemPayload(string $itemId, string $normalizedCode): array
    {
        $item = DB::table('item_instances')->where('item_id', $itemId)->first();
        if ($item === null) {
            return $this->notResolved($normalizedCode, 'PHYSICAL_CODE_NOT_FOUND', 'Không tìm thấy hiện vật ứng với mã này.');
        }
        $sku = DB::table('sku')->where('sku_id', $item->sku_id)->first();

        $base = [
            'resolved' => true,
            'item_type' => 'ITEM',
            'requires_quantity' => false,
            'normalized_code_value' => $normalizedCode,
            'item_id' => (string) $item->item_id,
            'item_status' => (string) $item->item_status,
            'serial_number' => $item->serial_number !== null ? (string) $item->serial_number : null,
            'container_id' => null,
            'box_code' => null,
            'available_qty' => null,
            'sku_id' => $sku !== null ? (string) $sku->sku_id : null,
            'sku_code' => $sku !== null ? (string) $sku->sku_code : null,
            'sku_name' => $sku !== null ? (string) $sku->sku_name : null,
            'sku_type' => $sku !== null ? strtoupper((string) $sku->sku_type) : null,
            'is_serial_tracked' => $sku !== null ? (bool) $sku->is_serial_tracked : null,
        ];

        if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT' || strtoupper((string) $sku->status) !== 'ACTIVE') {
            return $this->ineligible($base, 'COMPONENT_SKU_REQUIRED', 'Mã này không phải linh kiện (SKU COMPONENT ACTIVE).');
        }
        if ((string) $item->item_status !== 'IN_STOCK') {
            return $this->ineligible($base, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Linh kiện không còn trong kho (item_status khác IN_STOCK).');
        }

        // Cùng luật với scan(): unique index `uk_component_issue_active_item` cấm một
        // hiện vật nằm ACTIVE ở hai phiếu xuất linh kiện cùng lúc.
        $activeElsewhere = DB::table('component_issue_items as ci')
            ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
            ->join('component_issue_documents as cid', 'cid.id', '=', 'cl.component_issue_document_id')
            ->where('ci.item_id', $item->item_id)
            ->where('ci.status', 'ACTIVE')
            ->value('cid.component_issue_no');
        if ($activeElsewhere !== null) {
            return $this->ineligible(
                [...$base, 'active_component_issue_no' => (string) $activeElsewhere],
                'COMPONENT_ITEM_ACTIVE_ELSEWHERE',
                "Linh kiện này đang được quét ở phiếu xuất linh kiện khác ({$activeElsewhere}) và chưa hủy/hoàn tất."
            );
        }

        return [...$base, 'eligible_for_component_issue' => true, 'eligibility_code' => 'ELIGIBLE', 'eligibility_message' => null];
    }

    /** @return array<string, mixed> */
    private function containerPayload(string $containerId, string $normalizedCode): array
    {
        $container = DB::table('containers')->where('container_id', $containerId)->first();
        if ($container === null) {
            return $this->notResolved($normalizedCode, 'PHYSICAL_CODE_NOT_FOUND', 'Không tìm thấy hộp ứng với mã này.');
        }
        $sku = $container->primary_sku_id !== null
            ? DB::table('sku')->where('sku_id', $container->primary_sku_id)->first()
            : null;
        $availableQty = $sku !== null
            ? (int) DB::table('container_items')
                ->where('container_id', $containerId)
                ->where('sku_id', $sku->sku_id)
                ->where('is_active', true)
                ->sum('quantity')
            : 0;

        $base = [
            'resolved' => true,
            'item_type' => 'BOX',
            'requires_quantity' => true,
            'normalized_code_value' => $normalizedCode,
            'item_id' => null,
            'item_status' => null,
            'serial_number' => null,
            'container_id' => (string) $container->container_id,
            'container_status' => strtoupper((string) $container->status),
            'box_code' => (string) $container->container_code,
            'available_qty' => $availableQty,
            'sku_id' => $sku !== null ? (string) $sku->sku_id : null,
            'sku_code' => $sku !== null ? (string) $sku->sku_code : null,
            'sku_name' => $sku !== null ? (string) $sku->sku_name : null,
            'sku_type' => $sku !== null ? strtoupper((string) $sku->sku_type) : null,
            'is_serial_tracked' => $sku !== null ? (bool) $sku->is_serial_tracked : null,
        ];

        if (strtoupper((string) $container->container_type) !== 'BOX'
            || ! in_array(strtoupper((string) $container->status), self::STOCK_BEARING_BOX_STATUSES, true)) {
            return $this->ineligible($base, 'COMPONENT_BOX_INVALID', 'Hộp không còn ở trạng thái mang tồn (đã CLOSED/ISSUED hoặc rỗng).');
        }
        if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT' || strtoupper((string) $sku->status) !== 'ACTIVE') {
            return $this->ineligible($base, 'COMPONENT_SKU_REQUIRED', 'Hộp không gắn với SKU linh kiện ACTIVE.');
        }
        if ((bool) $sku->is_serial_tracked) {
            return $this->ineligible($base, 'SERIAL_COMPONENT_REQUIRES_ITEM', 'Linh kiện này dán mã từng cái; phải quét mã trên linh kiện chứ không quét hộp.');
        }
        if ($availableQty <= 0) {
            return $this->ineligible($base, 'COMPONENT_BOX_INSUFFICIENT', 'Hộp không còn linh kiện nào để xuất.');
        }

        return [...$base, 'eligible_for_component_issue' => true, 'eligibility_code' => 'ELIGIBLE', 'eligibility_message' => null];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function ineligible(array $base, string $code, string $message): array
    {
        return [...$base, 'eligible_for_component_issue' => false, 'eligibility_code' => $code, 'eligibility_message' => $message];
    }

    /** @return array<string, mixed> */
    private function notResolved(string $normalizedCode, string $code, string $message): array
    {
        return [
            'resolved' => false,
            'item_type' => null,
            'requires_quantity' => false,
            'normalized_code_value' => $normalizedCode,
            'item_id' => null,
            'item_status' => null,
            'serial_number' => null,
            'container_id' => null,
            'box_code' => null,
            'available_qty' => null,
            'sku_id' => null,
            'sku_code' => null,
            'sku_name' => null,
            'sku_type' => null,
            'is_serial_tracked' => null,
            'eligible_for_component_issue' => false,
            'eligibility_code' => $code,
            'eligibility_message' => $message,
        ];
    }

    private function assertActiveWarehouse(string $warehouseId): void
    {
        if (! DB::table('warehouses')->where('id', $warehouseId)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Kho xuất linh kiện không tồn tại hoặc không ACTIVE.');
        }
    }

    private function assertRecordPayloadShape(Request $request): void
    {
        $payload = $request->all();
        $unexpected = array_values(array_diff(array_keys($payload), self::RECORD_FIELDS));

        $items = $payload['items'] ?? null;
        if (is_array($items)) {
            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (array_diff(array_keys($item), self::RECORD_ITEM_FIELDS) as $field) {
                    $unexpected[] = 'items.'.($index + 1).'.'.$field;
                }
            }
        }

        if ($unexpected === []) {
            return;
        }

        ApiError::abort(422, 'VALIDATION_ERROR', 'Dữ liệu gửi lên chứa trường không được khai báo trong hợp đồng xuất linh kiện.', [
            'unexpected_fields' => array_values(array_unique($unexpected)),
            'allowed_fields' => self::RECORD_FIELDS,
            'allowed_item_fields' => self::RECORD_ITEM_FIELDS,
        ]);
    }

    /**
     * Request con để gọi handler canonical trong cùng transaction — giữ nguyên
     * actor và request_id để audit/DenyAudit vẫn quy về đúng một lượt gọi.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function childRequest(Request $parent, array $payload, array $headers = []): Request
    {
        $child = Request::create(
            $parent->getUri(),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $child->setUserResolver(fn () => $parent->user());
        foreach ($parent->attributes->all() as $key => $value) {
            $child->attributes->set($key, $value);
        }
        foreach ($headers as $name => $value) {
            $child->headers->set($name, $value);
        }
        $child->headers->set('Accept', 'application/json');

        return $child;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
        ];
    }
}
