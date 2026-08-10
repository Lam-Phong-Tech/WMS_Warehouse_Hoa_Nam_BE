<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class CurrentBaselineComponentReadController extends Controller
{
    use ApiResponse;

    public function show(string $id)
    {
        $document = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($document === null) {
            return $this->respondNotFound('Component Issue not found.');
        }

        $lines = DB::table('component_issue_lines')
            ->where('component_issue_document_id', $id)
            ->orderBy('line_no')
            ->get()
            ->map(fn (object $line): array => [
                ...(array) $line,
                'items' => DB::table('component_issue_items as ci')
                    ->leftJoin('scan_event as se', 'se.scan_event_id', '=', 'ci.scan_event_id')
                    ->where('ci.component_issue_line_id', $line->id)
                    ->orderBy('ci.selected_at')
                    ->get([
                        'ci.*',
                        'se.raw_code as scan_raw_code',
                        'se.result_status as scan_result_status',
                    ]),
            ]);

        return $this->respondSuccess([
            'document' => $document,
            'lines' => $lines,
        ]);
    }
}
