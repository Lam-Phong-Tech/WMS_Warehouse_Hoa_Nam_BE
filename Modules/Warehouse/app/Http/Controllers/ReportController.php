<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Báo cáo Xuất-Nhập-Tồn (XNT) — port ý tưởng từ ReportController của M2, đơn giản hoá cho 1 kho. */
class ReportController extends Controller
{
    use ApiResponse;

    public function inventorySummary(Request $request)
    {
        $query = DB::table('inventory_balances as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ib.warehouse_id')
            ->select('w.code as warehouse_code', 'w.name as warehouse_name', 'p.code as product_code', 'p.name as product_name', 'ib.on_hand_qty', 'ib.available_qty', 'ib.reserved_qty');

        if ($request->filled('warehouse_id')) {
            $query->where('ib.warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('ib.product_id', $request->input('product_id'));
        }

        return $this->respondSuccess($query->orderBy('p.code')->get());
    }

    /** Xuất-nhập-tồn theo khoảng thời gian: tồn đầu kỳ + tổng nhập + tổng xuất = tồn cuối kỳ. */
    public function stockMovementSummary(Request $request)
    {
        $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        $from = $request->input('from_date');
        $to = $request->input('to_date');

        $query = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->selectRaw('w.code as warehouse_code, p.code as product_code, p.name as product_name,'
                ."SUM(CASE WHEN m.movement_type IN ('RECEIPT_IN','TRANSFER_IN','TRANSFER_RETURN') THEN m.qty_delta ELSE 0 END) as total_in,"
                ."SUM(CASE WHEN m.movement_type IN ('ISSUE_OUT','TRANSFER_OUT') THEN -m.qty_delta ELSE 0 END) as total_out")
            ->whereBetween('m.occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('w.code', 'p.code', 'p.name');

        if ($request->filled('warehouse_id')) {
            $query->where('m.warehouse_id', $request->input('warehouse_id'));
        }

        return $this->respondSuccess([
            'from_date' => $from,
            'to_date' => $to,
            'lines' => $query->orderBy('p.code')->get(),
        ]);
    }
}
