<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\SafeStockAlertHistory;

class SafeStockAlertController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = SafeStockAlertHistory::query();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }
}
