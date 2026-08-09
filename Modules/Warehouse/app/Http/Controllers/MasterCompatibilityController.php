<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;

/**
 * Stable compatibility surface for legacy master endpoints. Data is sourced
 * from canonical product_masters/SKU/import_batches only.
 */
class MasterCompatibilityController extends Controller
{
    use ApiResponse;

    public function productCategories(Request $request)
    {
        $query = ProductMaster::query()
            ->where('master_type', 'CATEGORY')
            ->orderBy('code');
        if ($request->filled('keyword')) {
            $keyword = '%'.$request->input('keyword').'%';
            $query->where(fn ($q) => $q->where('code', 'like', $keyword)->orWhere('name', 'like', $keyword));
        }

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function uoms(Request $request)
    {
        $units = Sku::query()->whereNotNull('unit')->where('unit', '!=', '')
            ->distinct()->orderBy('unit')->pluck('unit')->values();
        if ($units->isEmpty()) {
            $units = collect(['cái']);
        }

        return $this->pageArray($request, $units->map(fn ($unit) => [
            'id' => (string) $unit,
            'code' => (string) $unit,
            'name' => (string) $unit,
        ])->all());
    }

    public function suppliers(Request $request)
    {
        $names = DB::table('import_batches')
            ->whereNotNull('supplier_name')->where('supplier_name', '!=', '')
            ->distinct()->orderBy('supplier_name')->pluck('supplier_name')->values();

        return $this->pageArray($request, $names->map(fn ($name) => [
            'id' => (string) $name,
            'code' => (string) $name,
            'name' => (string) $name,
        ])->all());
    }

    private function pageArray(Request $request, array $rows)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $this->perPage($request);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
                'total' => count($rows),
                'request_id' => $this->currentRequestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', 15)));
    }
}
