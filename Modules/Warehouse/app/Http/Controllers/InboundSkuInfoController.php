<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\SkuAggregateService;
use Modules\Warehouse\Support\SkuInboundInfoRule;
use OpenApi\Attributes as OA;

/**
 * Bổ sung thông tin cho SKU MỚI ngay trong phiếu nhập đang quét.
 *
 * Vì sao KHÔNG dùng thẳng PATCH /skus/{id}: route đó gác `sku.manage`, mà vai
 * Nhân viên kho (người đứng quét và Post Receipt) chỉ có `sku.view` theo ma trận
 * BA. Cấp `sku.manage` cho vai kho sẽ mở luôn quyền sửa MỌI SKU ở màn Danh mục —
 * rộng hơn nhiều so với nhu cầu. Endpoint này gác `inbound.scan` nhưng bị bó rất
 * chặt: chỉ SKU đang nằm trên dòng của CHÍNH phiếu nhập đó, phiếu còn
 * DRAFT/SCANNING, và chỉ SKU chưa từng vào kho (hoặc còn thiếu thông tin).
 *
 * Ngưỡng "đủ thông tin" nằm ở SkuInboundInfoRule — dùng chung với cờ trên từng
 * dòng phiếu và guard chặn Post Receipt, để ba chỗ không bao giờ lệch nhau.
 */
class InboundSkuInfoController extends Controller
{
    use CachesReadPayload;

    /**
     * Bốn danh mục là TÙY CHỌN có chủ đích: người nhận hàng chỉ nắm chắc tên và
     * đơn vị tính, còn Thương hiệu/Nhóm/Sê-ri/Nguồn điện phụ thuộc danh mục có
     * sẵn — bắt đủ ngay tại quầy sẽ kẹt khi chưa có mục phù hợp. Phần còn thiếu
     * để người quản lý danh mục hoàn thiện sau qua bộ lọc "SKU chờ điền thông
     * tin" ở màn Danh mục SKU.
     *
     * @var array<string, string> field payload => bảng danh mục
     */
    private const OPTIONAL_MASTERS = [
        'brand_id' => 'brands',
        'category_id' => 'product_categories',
        'model_id' => 'product_models',
        'power_source_id' => 'power_sources',
    ];

    public function __construct(private readonly SkuAggregateService $skus) {}

    #[OA\Patch(
        path: '/api/v1/inbound-documents/{id}/lines/{lineId}/sku',
        operationId: 'inbound_line_sku_complete_info',
        summary: 'Bổ sung thông tin cho SKU mới trên một dòng phiếu nhập',
        description: 'Chỉ dùng cho SKU sinh tự động lúc quét (tên tạm bằng mã, chưa có đơn vị tính). Đủ thông tin mới được Post Receipt.',
        security: [['bearerAuth' => []]],
        tags: ['06. Inbound'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku_name', 'unit'],
                properties: [
                    new OA\Property(property: 'sku_name', type: 'string', maxLength: 255, description: 'Tên đọc được, KHÔNG được trùng mã SKU.', example: 'Máy khoan búa DCPB16'),
                    new OA\Property(property: 'unit', type: 'string', maxLength: 30, example: 'Cái'),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'model_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'power_source_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã bổ sung thông tin SKU'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Thiếu quyền inbound.scan'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiếu/dòng phiếu'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT — phiếu đã ghi sổ/huỷ, hoặc SKU đã có tồn kho và đủ thông tin'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ hoặc vẫn chưa đủ thông tin'),
        ]
    )]
    public function complete(Request $request, string $id, string $lineId): JsonResponse
    {
        $validated = $request->validate([
            'sku_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'brand_id' => ['sometimes', 'nullable', 'uuid', 'exists:brands,id'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_categories,id'],
            'model_id' => ['sometimes', 'nullable', 'uuid', 'exists:product_models,id'],
            'power_source_id' => ['sometimes', 'nullable', 'uuid', 'exists:power_sources,id'],
        ]);

        $actorId = $request->user()?->user_id;
        $requestId = $request->attributes->get('request_id');

        $result = DB::transaction(function () use ($id, $lineId, $validated, $actorId, $requestId): array {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Phiếu đã ghi sổ hoặc đã huỷ — không sửa được thông tin SKU ở đây nữa.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $lineId)
                ->where('stock_document_id', $id)
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy dòng hàng trong phiếu nhập.');
            }

            $skuId = (string) $line->product_id;
            $sku = DB::table('sku')->where('sku_id', $skuId)->lockForUpdate()->first();
            if ($sku === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy SKU của dòng hàng.');
            }

            // Hàng rào phạm vi: endpoint này để hoàn thiện SKU MỚI, không phải cửa
            // sau để sửa tên SKU đang lưu hành. Cho sửa khi (a) SKU còn thiếu thông
            // tin — kể cả SKU cũ dở dang, nếu không thì phiếu bị guard chặn mà không
            // ai gỡ được; hoặc (b) SKU chưa từng vào kho, để người vừa nhập còn sửa
            // được lỗi gõ trước lúc Post.
            $stillMissing = SkuInboundInfoRule::missingFields($sku->sku_code, $sku->sku_name, $sku->unit) !== [];
            if (! $stillMissing && $this->hasEnteredStock($skuId)) {
                ApiError::abort(
                    409,
                    'SKU_ALREADY_IN_STOCK',
                    'SKU này đã có trong kho và đã đủ thông tin — sửa ở màn Danh mục SKU.'
                );
            }

            $name = trim((string) $validated['sku_name']);
            $unit = trim((string) $validated['unit']);
            $missingAfter = SkuInboundInfoRule::missingFields($sku->sku_code, $name, $unit);
            if ($missingAfter !== []) {
                ApiError::abort(
                    422,
                    'SKU_INFO_STILL_INCOMPLETE',
                    'Tên SKU phải khác mã SKU và đơn vị tính không được để trống.',
                    ['missing_fields' => $missingAfter, 'sku_code' => (string) $sku->sku_code]
                );
            }

            $version = (int) ($sku->version ?? 1);
            $this->skus->update($skuId, ['sku_name' => $name, 'unit' => $unit], $version, $actorId, $requestId);
            $this->applyOptionalMasters($skuId, $validated, $version + 1);

            AuditLogger::record(
                action: 'INBOUND_SKU_INFO_COMPLETED',
                objectType: 'sku',
                objectId: $skuId,
                actorId: $actorId !== null ? (string) $actorId : null,
                requestId: $requestId,
                beforeData: [
                    'sku_name' => (string) $sku->sku_name,
                    'unit' => (string) ($sku->unit ?? ''),
                ],
                afterData: [
                    'sku_name' => $name,
                    'unit' => $unit,
                    'stock_document_id' => $id,
                    'stock_document_line_id' => $lineId,
                ],
            );

            // Đổi tên/ĐVT SKU là đổi nội dung mà danh sách + chi tiết phiếu nhập đang
            // phục vụ từ read cache.
            DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

            $fresh = DB::table('sku')->where('sku_id', $skuId)->first();

            return [
                'document_id' => $id,
                'line_id' => $lineId,
                'sku_id' => $skuId,
                'sku_code' => (string) $fresh->sku_code,
                'sku_name' => (string) $fresh->sku_name,
                'unit' => (string) ($fresh->unit ?? ''),
                'brand_id' => $fresh->brand_id !== null ? (string) $fresh->brand_id : null,
                'category_id' => $fresh->category_id !== null ? (string) $fresh->category_id : null,
                'model_id' => $fresh->model_id !== null ? (string) $fresh->model_id : null,
                'power_source_id' => $fresh->power_source_id !== null ? (string) $fresh->power_source_id : null,
                'missing_info' => SkuInboundInfoRule::missingFields($fresh->sku_code, $fresh->sku_name, $fresh->unit),
                'info_complete' => SkuInboundInfoRule::isComplete($fresh->sku_code, $fresh->sku_name, $fresh->unit),
            ];
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Đã bổ sung thông tin cho SKU mới.',
            'data' => $result,
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    /**
     * "Đã từng vào kho" = có movement hoặc còn tồn dương. Cùng cặp bảng mà
     * ProductController::assertImmutableAfterStock() dùng để khoá mã/tracking, nên
     * hai chỗ hiểu "SKU đã lưu hành" giống hệt nhau.
     */
    private function hasEnteredStock(string $skuId): bool
    {
        return DB::table('stock_movements')->where('product_id', $skuId)->exists()
            || DB::table('inventory_balances')
                ->where('product_id', $skuId)
                ->where('on_hand_qty', '>', 0)
                ->exists();
    }

    /**
     * Bốn danh mục tùy chọn: vắng key = không đụng tới, gửi null = xoá về trống,
     * gửi id = phải tồn tại và ACTIVE. `resolveMaster()` của
     * CurrentBaselineSkuController không dùng lại được vì nó coi null là "tra theo
     * code" rồi 422.
     */
    private function applyOptionalMasters(string $skuId, array $payload, int $version): void
    {
        $updates = ['version' => $version, 'updated_at' => now()];

        foreach (self::OPTIONAL_MASTERS as $field => $table) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if ($value === null || $value === '') {
                $updates[$field] = null;

                continue;
            }

            $master = DB::table($table)->where('id', $value)->first();
            if ($master === null || strtoupper((string) $master->status) !== 'ACTIVE') {
                ApiError::abort(422, 'MASTER_NOT_ACTIVE', "{$field} không tồn tại hoặc không ACTIVE.");
            }
            $updates[$field] = $master->id;
        }

        DB::table('sku')->where('sku_id', $skuId)->update($updates);
    }
}
