<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backward-compatible BA M1 warranty adapter.
 *
 * New clients send defect_ids[] / defect_codes[] and expected_completion_date.
 * Old clients can keep sending defect_id / defect_code. The legacy primary defect
 * stays in warranty_case.defect_id while the complete set is persisted in
 * warranty_case_defects.
 */
class BaM1WarrantyController extends CurrentBaselineWarrantyController
{
    public function storeWarrantyCase(Request $request)
    {
        $this->validateBaFields($request);
        $defectIds = $this->resolveRequestedDefectIds($request);
        $this->mergePrimaryDefect($request, $defectIds);

        return DB::transaction(function () use ($request, $defectIds) {
            $response = parent::storeWarrantyCase($request);
            $caseId = (string) data_get($response->getData(true), 'data.warranty_case_id', '');
            if ($caseId !== '') {
                $this->syncBaFields($caseId, $defectIds, $request);
                $this->augmentResponse($response, $caseId);
            }

            return $response;
        }, 3);
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $this->validateBaFields($request);
        $hasDefectSet = $request->has('defect_ids') || $request->has('defect_codes');
        $defectIds = $hasDefectSet ? $this->resolveRequestedDefectIds($request) : null;
        if ($defectIds !== null) {
            $this->mergePrimaryDefect($request, $defectIds);
        }

        // The parent rejects an otherwise-empty PATCH. expected_completion_date is
        // a real BA field, so use the current condition_status as a no-op legacy
        // field to let the canonical version/state/audit machinery execute once.
        if ($request->has('expected_completion_date') && $this->hasOnlyBaFields($request)) {
            $current = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
            if ($current === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Warranty case not found.');
            }
            $request->merge(['condition_status' => $current->condition_status]);
        }

        return DB::transaction(function () use ($request, $id, $defectIds) {
            $response = parent::updateWarrantyCase($request, $id);
            $this->syncBaFields($id, $defectIds, $request);
            $this->augmentResponse($response, $id);

            return $response;
        }, 3);
    }

    public function showWarrantyCase(Request $request, string $id)
    {
        $response = parent::showWarrantyCase($request, $id);
        $this->augmentResponse($response, $id);

        return $response;
    }

    private function validateBaFields(Request $request): void
    {
        $request->validate([
            'defect_ids' => ['sometimes', 'array', 'min:1'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'defect_codes' => ['sometimes', 'array', 'min:1'],
            'defect_codes.*' => ['string', 'max:80', 'distinct'],
            'expected_completion_date' => ['nullable', 'date'],
        ]);
    }

    /** @return list<string> */
    private function resolveRequestedDefectIds(Request $request): array
    {
        $ids = array_values(array_filter(array_map('strval', (array) $request->input('defect_ids', []))));
        $codes = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) $request->input('defect_codes', [])
        )));

        if ($ids === [] && $codes === []) {
            $legacyId = trim((string) $request->input('defect_id', ''));
            $legacyCode = trim((string) $request->input('defect_code', ''));
            if ($legacyId !== '') {
                $ids[] = $legacyId;
            } elseif ($legacyCode !== '') {
                $codes[] = $legacyCode;
            } else {
                return [];
            }
        }

        if ($codes !== []) {
            $rows = DB::table('defect_catalogs')
                ->where('status', 'ACTIVE')
                ->whereIn('defect_code', $codes)
                ->get(['defect_id', 'defect_code']);
            $foundCodes = $rows->pluck('defect_code')->map(fn ($v) => (string) $v)->all();
            $missing = array_values(array_diff($codes, $foundCodes));
            if ($missing !== []) {
                ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy một hoặc nhiều bệnh/lỗi ACTIVE.', [
                    'defect_codes' => $missing,
                ]);
            }
            foreach ($rows as $row) {
                $ids[] = (string) $row->defect_id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            $existing = DB::table('defect_catalogs')
                ->where('status', 'ACTIVE')
                ->whereIn('defect_id', $ids)
                ->pluck('defect_id')
                ->map(fn ($v) => (string) $v)
                ->all();
            $missing = array_values(array_diff($ids, $existing));
            if ($missing !== []) {
                ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy một hoặc nhiều bệnh/lỗi ACTIVE.', [
                    'defect_ids' => $missing,
                ]);
            }
        }

        return $ids;
    }

    /** @param list<string> $defectIds */
    private function mergePrimaryDefect(Request $request, array $defectIds): void
    {
        if ($defectIds !== []) {
            $request->merge(['defect_id' => $defectIds[0], 'defect_code' => null]);
        }
    }

    /** @param list<string>|null $defectIds */
    private function syncBaFields(string $caseId, ?array $defectIds, Request $request): void
    {
        if ($defectIds !== null && Schema::hasTable('warranty_case_defects')) {
            DB::table('warranty_case_defects')->where('warranty_case_id', $caseId)->delete();
            foreach ($defectIds as $index => $defectId) {
                DB::table('warranty_case_defects')->insert([
                    'warranty_case_id' => $caseId,
                    'defect_id' => $defectId,
                    'is_primary' => $index === 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($request->has('expected_completion_date') && Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            DB::table('warranty_case')->where('warranty_case_id', $caseId)->update([
                'expected_completion_date' => $request->input('expected_completion_date'),
                'updated_at' => now(),
            ]);
        }
    }

    private function augmentResponse(JsonResponse $response, string $caseId): void
    {
        $body = $response->getData(true);
        if (! isset($body['data']) || ! is_array($body['data'])) {
            return;
        }

        $defects = [];
        if (Schema::hasTable('warranty_case_defects')) {
            $defects = DB::table('warranty_case_defects as wcd')
                ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                ->where('wcd.warranty_case_id', $caseId)
                ->orderByDesc('wcd.is_primary')
                ->orderBy('dc.defect_code')
                ->get(['dc.defect_id', 'dc.defect_code', 'dc.defect_name', 'wcd.is_primary'])
                ->map(fn (object $row): array => [
                    'defect_id' => (string) $row->defect_id,
                    'defect_code' => (string) $row->defect_code,
                    'defect_name' => (string) $row->defect_name,
                    'is_primary' => (bool) $row->is_primary,
                ])->all();
        }

        $body['data']['defects'] = $defects;
        $body['data']['defect_ids'] = array_values(array_map(
            static fn (array $defect): string => $defect['defect_id'],
            $defects
        ));
        if (Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            $body['data']['expected_completion_date'] = DB::table('warranty_case')
                ->where('warranty_case_id', $caseId)
                ->value('expected_completion_date');
        }

        $response->setData($body);
    }

    private function hasOnlyBaFields(Request $request): bool
    {
        $businessKeys = array_values(array_diff(array_keys($request->all()), [
            'defect_ids', 'defect_codes', 'expected_completion_date', 'defect_id', 'defect_code',
        ]));

        return $businessKeys === [];
    }
}
