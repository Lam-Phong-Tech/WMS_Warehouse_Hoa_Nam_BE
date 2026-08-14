<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Modules\Warehouse\Http\Requests\StoreScanClassificationRequest;
use Modules\Warehouse\Services\ScanRecordingService;
use Modules\Warehouse\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * Scan classify - Phân loại mã QR/Barcode/Container/Item trước khi FE áp vào inbound/outbound/stocktake/warranty.
 */
#[OA\Post(
    path: '/api/v1/scan/classify',
    operationId: 'scan_classify',
    summary: 'Phân loại mã scan (QR/Barcode/Container/Item)',
    description: 'Nhận physical code thực, kiểm tra trạng thái ACTIVE, ghi scan batch/event idempotent và trả về item hoặc container đã phân giải.',
    security: [['bearerAuth' => []]],
    tags: ['11. Scan'],
    parameters: [
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['raw_code', 'context', 'warehouse_id'],
            properties: [
                new OA\Property(property: 'raw_code', type: 'string', maxLength: 100, description: 'Mã QR/Barcode/Container/Item cần phân loại'),
                new OA\Property(property: 'context', type: 'string', enum: ['INBOUND', 'OUTBOUND', 'STOCKTAKE', 'WARRANTY_TEMP_ONLY'], description: 'Ngữ cảnh sử dụng'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', description: 'ID kho tổng Hoa Nam'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Phân loại thành công'),
        new OA\Response(response: 400, description: 'Dữ liệu không hợp lệ'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_document.post'),
        new OA\Response(response: 404, description: 'SCAN_CODE_NOT_FOUND'),
        new OA\Response(response: 409, description: 'SCAN_CODE_INACTIVE, SCAN_CONTAINER_INACTIVE hoặc SCAN_CODE_UNASSIGNED'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class ScanController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ScanRecordingService $scanRecordingService) {}

    /**
     * Phân loại mã scan.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function classify(StoreScanClassificationRequest $request)
    {
        $idemKey = $request->header('Idempotency-Key');
        if (! $idemKey) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }

        $validated = $request->validated();

        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);
        if ($warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'STATE_CONFLICT', "Kho \"{$warehouse->name}\" đang ngừng hoạt động.");
        }

        $hash = IdempotencyStore::requestHash($request);
        [$code, $body] = IdempotencyStore::handle($idemKey, 'scan_classify', (string) auth('api')->id(), $hash, function () use ($validated, $warehouse, $request) {
            $result = $this->scanRecordingService->classifyAndRecord($validated, $warehouse, auth('api')->id(), $request->attributes->get('request_id'));

            return [200, ['success' => true, 'message' => 'Scan classified', 'data' => $result, 'meta' => ['request_id' => $this->currentRequestId()]]];
        });

        return response()->json($body, $code);
    }
}
