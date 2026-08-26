<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CurrentBaselineImportReadController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/outbound-request-imports/{id}/preview',
        operationId: 'import_read_preview',
        summary: 'Preview kết quả validate import yêu cầu xuất',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'import + rows (row_no/status/normalized/errors) + can_consume'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền outbound.request.import hoặc outbound.import'),
            new OA\Response(response: 404, description: 'Import not found'),
        ]
    )]
    public function preview(string $id)
    {
        $import = DB::table('outbound_request_imports')->where('id', $id)->first();
        if ($import === null) {
            return $this->respondNotFound('Không tìm thấy Import.');
        }

        $rows = DB::table('outbound_request_import_rows')
            ->where('import_id', $id)
            ->orderBy('row_no')
            ->get()
            ->map(fn (object $row): array => [
                'row_no' => (int) $row->row_no,
                'status' => (string) $row->validation_status,
                'normalized' => json_decode((string) $row->normalized_payload, true),
                'errors' => json_decode((string) $row->validation_errors, true) ?: [],
            ]);

        return $this->respondSuccess([
            'import' => $import,
            'rows' => $rows,
            'can_consume' => $import->status === 'VALIDATED' && (int) $import->error_rows === 0,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/outbound-request-imports/{id}/errors',
        operationId: 'import_read_errors',
        summary: 'Danh sách lỗi row/field của import yêu cầu xuất',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'import_id + status + error_count + errors (row/field/error_code/message)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền outbound.request.import hoặc outbound.import'),
            new OA\Response(response: 404, description: 'Import not found'),
        ]
    )]
    public function errors(string $id)
    {
        $import = DB::table('outbound_request_imports')->where('id', $id)->first();
        if ($import === null) {
            return $this->respondNotFound('Không tìm thấy Import.');
        }

        $errors = DB::table('outbound_request_import_rows')
            ->where('import_id', $id)
            ->where('validation_status', '!=', 'VALID')
            ->orderBy('row_no')
            ->get()
            ->flatMap(function (object $row): array {
                $decoded = json_decode((string) $row->validation_errors, true) ?: [];

                return array_map(function (array $error) use ($row): array {
                    return [
                        'row' => (int) ($error['row'] ?? $row->row_no),
                        'field' => $error['field'] ?? null,
                        'error_code' => $error['error_code'] ?? 'VALIDATION_ERROR',
                        'message' => $error['message'] ?? 'Invalid row.',
                    ];
                }, $decoded);
            })
            ->values();

        return $this->respondSuccess([
            'import_id' => $id,
            'status' => $import->status,
            'error_count' => $errors->count(),
            'errors' => $errors,
        ]);
    }
}
