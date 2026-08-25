<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility adapter for the 2026-08-25 BA warranty contract.
 * Canonical persistence remains in CurrentBaselineWarrantyController:
 * - defect_ids[] is persisted through warranty_case_defects with its required UUID;
 * - estimated_completion_date is the canonical field.
 * This adapter only maps legacy aliases and enriches the response.
 */
class BaM1WarrantyController extends CurrentBaselineWarrantyController
{
    public function storeWarrantyCase(Request $request)
    {
        $this->normalizeAliases($request);
        $response = parent::storeWarrantyCase($request);
        $caseId = (string) data_get($response->getData(true), 'data.warranty_case_id', '');
        if ($caseId !== '') {
            $this->augmentResponse($response, $caseId);
        }

        return $response;
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $this->normalizeAliases($request);
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

    private function normalizeAliases(Request $request): void
    {
        $request->validate([
            'defect_codes' => ['sometimes', 'array', 'min:1'],
            'defect_codes.*' => ['string', 'max:80', 'distinct'],
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        if ($request->has('expected_completion_date') && ! $request->has('estimated_completion_date')) {
            $request->merge([
                'estimated_completion_date' => $request->input('expected_completion_date'),
            ]);
        }

        $codes = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) $request->input('defect_codes', [])
        )));
        if ($codes === []) {
            return;
        }

        $rows = DB::table('defect_catalogs')
            ->where('status', 'ACTIVE')
            ->whereIn('defect_code', $codes)
            ->get(['defect_id', 'defect_code']);
        $foundCodes = $rows->pluck('defect_code')->map(fn ($value): string => (string) $value)->all();
        $missing = array_values(array_diff($codes, $foundCodes));
        if ($missing !== []) {
            ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy một hoặc nhiều bệnh/lỗi ACTIVE.', [
                'defect_codes' => $missing,
            ]);
        }

        $ids = array_values(array_unique(array_merge(
            array_values(array_filter(array_map('strval', (array) $request->input('defect_ids', [])))),
            $rows->pluck('defect_id')->map(fn ($value): string => (string) $value)->all(),
        )));
        $request->merge(['defect_ids' => $ids]);
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
            $hasPrimaryColumn = Schema::hasColumn('warranty_case_defects', 'is_primary');
            $defects = DB::table('warranty_case_defects as wcd')
                ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                ->where('wcd.warranty_case_id', $caseId)
                ->orderBy('wcd.created_at')
                ->get(array_merge(
                    ['dc.defect_id', 'dc.defect_code', 'dc.defect_name'],
                    $hasPrimaryColumn ? ['wcd.is_primary'] : []
                ))
                ->map(fn (object $row): array => [
                    'defect_id' => (string) $row->defect_id,
                    'defect_code' => (string) $row->defect_code,
                    'defect_name' => (string) $row->defect_name,
                    'is_primary' => $hasPrimaryColumn
                        ? (bool) $row->is_primary
                        : (string) $row->defect_id === (string) ($case->defect_id ?? ''),
                ])->values()->all();
        }

        $body['data']['defects'] = $defects;
        $body['data']['defect_ids'] = array_values(array_map(
            static fn (array $defect): string => $defect['defect_id'],
            $defects
        ));
        $body['data']['estimated_completion_date'] = $case->estimated_completion_date ?? null;
        // Compatibility alias for already-shipped FE clients.
        $body['data']['expected_completion_date'] = $case->estimated_completion_date ?? null;
        $response->setData($body);
    }
}
