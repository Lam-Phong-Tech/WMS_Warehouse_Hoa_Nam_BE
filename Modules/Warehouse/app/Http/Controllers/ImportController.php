<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Services\ImportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportService $importService) {}

    #[OA\Get(
        path: '/api/v1/skus/import-template',
        operationId: 'sku_import_template',
        summary: 'Tải template Excel SKU chuẩn WWHN-172',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        responses: [
            new OA\Response(response: 200, description: 'XLSX template'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
        ]
    )]
    public function downloadTemplate(): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/WWHN-172-SKU-import-template-'.Str::uuid().'.xlsx';
        $this->importService->createTemplate($path);

        return response()
            ->download($path, 'WWHN-172-SKU-import-template-v1.1.0.xlsx')
            ->deleteFileAfterSend(true);
    }

    #[OA\Post(
        path: '/api/v1/skus/import-excel',
        operationId: 'sku_import_excel',
        summary: 'Import SKUs từ Excel theo cơ chế all-or-nothing',
        description: 'Validate toàn bộ file trước khi ghi. Có bất kỳ lỗi nào thì committed=false và không tạo/cập nhật SKU.',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'update_existing', type: 'boolean', default: false),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Import committed atomically'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.manage permission'),
            new OA\Response(response: 409, description: 'Concurrent unique conflict; no partial write'),
            new OA\Response(response: 422, description: 'File/row validation failed; no business side effect'),
        ]
    )]
    public function importExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'update_existing' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $path = $file->store('temp/imports', 'local');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Không lưu được file import tạm thời.');
        }
        $localDisk = Storage::disk('local');
        $fullPath = $localDisk->path($path);

        try {
            $result = $this->importService->import(
                $fullPath,
                (bool) ($validated['update_existing'] ?? false),
                ($id = auth('api')->id()) === null ? null : (string) $id,
                is_string($request->attributes->get('request_id'))
                    ? $request->attributes->get('request_id')
                    : null,
                $file->getClientOriginalName(),
            );

            if (! $result['committed']) {
                return $this->respondError(
                    'Import bị từ chối; không có SKU nào được tạo hoặc cập nhật.',
                    $result['row_errors'],
                    422,
                    'SKU_IMPORT_VALIDATION_FAILED',
                    ['data' => $result],
                );
            }

            return $this->respondSuccess($result, 'Import SKU đã commit nguyên tử.');
        } catch (\InvalidArgumentException $e) {
            return $this->respondError(
                $e->getMessage(),
                [],
                422,
                'SKU_IMPORT_FILE_INVALID',
                ['data' => ['committed' => false, 'partial_write' => false]],
            );
        } finally {
            $localDisk->delete($path);
        }
    }
}
