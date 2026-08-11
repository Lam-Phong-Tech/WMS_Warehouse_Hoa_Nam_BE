<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class CurrentBaselineComponentIssueQueryController extends Controller
{
    use ApiResponse;

    public function show(string $id)
    {
        $document = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
        }

        $lines = DB::table('component_issue_lines')
            ->where('component_issue_document_id', $id)
            ->orderBy('line_no')
            ->get()
            ->map(function (object $line): array {
                $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
                $items = DB::table('component_issue_items')
                    ->where('component_issue_line_id', $line->id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (object $item): array => (array) $item)
                    ->all();

                return [
                    ...(array) $line,
                    'sku_code' => $sku?->sku_code,
                    'sku_name' => $sku?->sku_name,
                    'items' => $items,
                ];
            })
            ->all();

        return $this->respondSuccess([
            'document' => (array) $document,
            'lines' => $lines,
            'stock_effect' => $document->status === 'POSTED' ? 'DECREASE' : 'NONE',
            'movement_created' => $document->status === 'POSTED',
        ]);
    }
}
