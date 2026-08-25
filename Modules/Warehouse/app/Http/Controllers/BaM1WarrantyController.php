<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BA M1 warranty compatibility adapter.
 *
 * Canonical persistence is owned by CurrentBaselineWarrantyController:
 * - defect_ids[] is persisted to warranty_case_defects using the schema PK;
 * - warranty_case.defect_id remains the legacy/primary value;
 * - estimated_completion_date is the canonical completion-date field.
 *
 * This adapter only normalizes legacy aliases and enriches the response. It must
 * never write a second copy of the bridge because that previously caused a schema
 * mismatch (is_primary was not a real column and the required bridge UUID was
 * omitted).
 */
class BaM1WarrantyController extends CurrentBaselineWarrantyController
{
    public function storeWarrantyCase(Request $request)
    {
        $this->normalizeBaRequest($request);
        $response = parent::storeWarrantyCase($request);
        $caseId = (string) data_get($response->getData(true), 'data.warranty_case_id', '');
        if ($caseId !== '') {
            $this->augmentResponse($response, $caseId);
        }

        return $response;
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $this->normalizeBaRequest($request);
        $response = parent::updateWarrantyCase($request, $id);
        $this->augmentResponse($response, $id);

        return $response;
    }

    public function showWarrantyCase(Request $request, string $id)
    {
        $response = parent::showWarrantyCase($request, $id);
        $this->augmentResponse($response, $id);

        return $response;
    }

    private function normalizeBaRequest(Request $request): void
    {
        $request->validate([
            'defect_ids' => ['sometimes', 'array', 'min:1'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'defect_codes' => ['sometimes', 'array', 'min:1'],
            'defect_codes.*' => ['string', 'max:80', 'distinct'],
            'estimated_completion_date' => ['nullable', 'date', 'after_or_equal:today'],
            // Compatibility only. All new code must use estimated_completion_date.
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        if ($request->has('expected_completion_date') && ! $request->has('estimated_completion_date')) {
            $request->merge(['estimated_completion_date' => $request->input('expected_completion_date')]);
        }

        if ($request->has('defect_codes')) {
            $codes = array_values(array_unique(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) $request->input('defect_codes', [])
            ))));
            if ($codes !== []) {
                $rows = DB::table('defect_catalogs')
                    ->where('status', 'ACTIVE')
                    ->whereIn('defect_code', $codes)
                    ->get(['defect_id', 'defect_code']);
                $found = $rows->pluck('defect_code')->map(fn ($value): string => (string) $value)->all();
                $missing = array_values(array_diff($codes, $found));
                if ($missing !== []) {
                    ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy một hoặc nhiều bệnh/lỗi ACTIVE.', [
                        'defect_codes' => $missing,
                    ]);
                }
                $request->merge([
                    'defect_ids' => $rows->pluck('defect_id')->map(fn ($value): string => (string) $value)->values()->all(),
                ]);
            }
        }
    }

    private function augmentResponse(JsonResponse $response, string $caseId): void
    {
        $body = $response->getData(true);
        if (! isset($body['data']) || ! is_array($body['data'])) {
            return;
        }

        $case = DB::table('warranty_case')->where('warranty_case_id', $caseId)->first();
        if ($case === null) {
            return;
        }

        $defects = [];
        if (Schema::hasTable('warranty_case_defects')) {
            $defects = DB::table('warranty_case_defects as wcd')
                ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                ->where('wcd.warranty_case_id', $caseId)
                ->orderBy('wcd.created_at')
                ->orderBy('dc.defect_code')
                ->get(['dc.defect_id', 'dc.defect_code', 'dc.defect_name'])
                ->values()
                ->map(fn (object $row, int $index): array => [
                    'defect_id' => (string) $row->defect_id,
                    'defect_code' => (string) $row->defect_code,
                    'defect_name' => (string) $row->defect_name,
                    // Computed compatibility flag; it is not persisted in the bridge.
                    'is_primary' => $index === 0,
                ])->all();
        }

        if ($defects === [] && $case->defect_id !== null) {
            $legacy = DB::table('defect_catalogs')->where('defect_id', $case->defect_id)->first();
            if ($legacy !== null) {
                $defects[] = [
                    'defect_id' => (string) $legacy->defect_id,
                    'defect_code' => (string) $legacy->defect_code,
                    'defect_name' => (string) $legacy->defect_name,
                    'is_primary' => true,
                ];
            }
        }

        $body['data']['defects'] = $defects;
        $body['data']['defect_ids'] = array_values(array_map(
            static fn (array $defect): string => $defect['defect_id'],
            $defects
        ));
        $body['data']['estimated_completion_date'] = $case->estimated_completion_date ?? null;
        // Read-only compatibility alias for older FE builds.
        $body['data']['expected_completion_date'] = $case->estimated_completion_date ?? null;
        $response->setData($body);
    }
}
