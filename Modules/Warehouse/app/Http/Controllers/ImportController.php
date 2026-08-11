<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ImportService;
use OpenApi\Attributes as OA;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportService $importService) {}

    #[OA\Post(
        path: '/api/v1/skus/import-excel',
        operationId: 'sku_import_excel',
        summary: 'Import SKUs from Excel file',
        description: 'Bulk import product/SKU data from Excel file with validation and error reporting',
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
                        new OA\Property(
                            property: 'file',
                            type: 'string',
                            format: 'binary',
                            description: 'Excel file (.xlsx, .xls) containing SKU data'
                        ),
                        new OA\Property(
                            property: 'update_existing',
                            type: 'boolean',
                            description: 'Update existing SKUs with matching codes',
                            default: false
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import completed',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'total_rows', type: 'integer'),
                            new OA\Property(property: 'valid_rows', type: 'integer'),
                            new OA\Property(property: 'error_rows', type: 'integer'),
                            new OA\Property(property: 'inserted_rows', type: 'integer'),
                            new OA\Property(property: 'updated_rows', type: 'integer'),
                            new OA\Property(property: 'skipped_rows', type: 'integer'),
                            new OA\Property(property: 'partial_write', type: 'boolean'),
                            new OA\Property(property: 'row_errors', type: 'array', items: new OA\Items(type: 'object')),
                        ]),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing sku.manage permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB
            'update_existing' => 'nullable|boolean',
        ]);

        $file = $request->file('file');
        $updateExisting = $request->boolean('update_existing', false);

        // Store file temporarily
        $path = $file->store('temp/imports');
        $fullPath = Storage::path($path);

        try {
            $result = $this->importService->import($fullPath, $updateExisting);

            if ($result['error_rows'] === 0) {
                AuditLogger::record(
                    'IMPORT',
                    'product',
                    null,
                    auth('api')->id(),
                    $request->attributes->get('request_id'),
                    afterData: [
                        'file_name' => $file->getClientOriginalName(),
                        'total_rows' => $result['total_rows'],
                        'inserted_rows' => $result['inserted_rows'],
                        'updated_rows' => $result['updated_rows'],
                    ]
                );
            }

            $message = $result['error_rows'] > 0
                ? 'Import completed with errors'
                : 'Import completed successfully';

            return $this->respondSuccess($result, $message);
        } finally {
            // Clean up temp file
            Storage::delete($path);
        }
    }
}
