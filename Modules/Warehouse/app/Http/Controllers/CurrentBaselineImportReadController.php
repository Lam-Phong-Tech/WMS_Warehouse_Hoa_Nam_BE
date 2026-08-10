<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class CurrentBaselineImportReadController extends Controller
{
    use ApiResponse;

    public function preview(string $id)
    {
        $import = DB::table('outbound_request_imports')->where('id', $id)->first();
        if ($import === null) {
            return $this->respondNotFound('Import not found.');
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

    public function errors(string $id)
    {
        $import = DB::table('outbound_request_imports')->where('id', $id)->first();
        if ($import === null) {
            return $this->respondNotFound('Import not found.');
        }

        $errors = DB::table('outbound_request_import_rows')
            ->where('import_id', $id)
            ->where('validation_status', 'INVALID')
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
