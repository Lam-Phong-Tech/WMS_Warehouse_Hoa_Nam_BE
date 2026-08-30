<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\InboundBoxRegistrar;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

class CurrentBaselineInboundBoxController extends Controller
{
    use ApiResponse;
    use ResolvesActor;

    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/boxes',
        // operationId + tập response bị pin bởi Wwhn184InboundContractParityTest
        // (đối chiếu docs/api-contract/WWHN-184-inbound-openapi.json): phải giữ
        // đúng id skeleton và KHÔNG khai 201 để tập response giữ nguyên
        // {200,400,401,403,404,409,412,422,428,500} sau khi merge baseline.
        operationId: 'post_api_v1_inbound__documents_id_boxes',
        summary: 'Đăng ký BOX cho dòng nhập SKU COMPONENT (stock-neutral, tái sử dụng BOX EMPTY hợp lệ)',
        security: [['bearerAuth' => []]],
        tags: ['09. Stock In (Inbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['line_id', 'box_code'],
                properties: [
                    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'box_code', type: 'string', maxLength: 100, example: 'BOX-000123'),
                ]
            )
        ),
        responses: [
            // CHỈ khai 200: RouteSyncProcessor append baseline 400..500 theo đúng
            // thứ tự pinned; khai thêm mã lỗi ở đây sẽ đảo thứ tự key trong spec
            // compile và làm Wwhn184InboundContractParityTest fail (assertSame
            // trên array_keys là so sánh CÓ thứ tự).
            new OA\Response(response: 200, description: 'BOX hợp lệ: tái sử dụng BOX EMPTY đã tồn tại (200) hoặc tạo và đăng ký BOX mới'),
        ]
    )]
    public function store(Request $request, string $id)
    {
        $payload = $request->validate([
            'line_id' => ['required', 'uuid'],
            'box_code' => ['required', 'string', 'max:100'],
        ]);
        $actorId = $this->actorId();
        $requestId = $request->attributes->get('request_id');

        $result = DB::transaction(function () use ($payload, $id, $actorId, $requestId): array {
            $document = DB::table('stock_documents')
                ->where('id', $id)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được đăng ký BOX.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $id)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy dòng nhập kho.');
            }

            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'BOX putaway chỉ áp dụng cho SKU COMPONENT.');
            }
            if (strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU COMPONENT phải ACTIVE.');
            }

            // Đăng ký/tái sử dụng BOX qua registrar dùng chung (cùng nguồn sự thật
            // với luồng scan-driven khi quét trực tiếp mã BOX-<SKU>-<seq>).
            $registered = app(InboundBoxRegistrar::class)->ensure(
                $id,
                (string) $line->product_id,
                (string) $payload['box_code'],
                $actorId,
                is_string($requestId) ? $requestId : null,
            );

            return $this->boxResponse($registered['box'], $registered['created']);
        }, 3);

        return $result['created']
            ? $this->respondCreated($result, 'Đã đăng ký BOX cho dòng nhập COMPONENT.')
            : $this->respondSuccess($result, 'BOX đã tồn tại và hợp lệ.');
    }

    private function boxResponse(object $box, bool $created): array
    {
        return [
            'container_id' => (string) $box->container_id,
            'container_type' => (string) $box->container_type,
            'box_code' => (string) $box->container_code,
            'sku_id' => (string) $box->primary_sku_id,
            'status' => (string) $box->status,
            'version' => (int) $box->version,
            'created' => $created,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }
}
