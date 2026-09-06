<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class CurrentBaselineComponentReadController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public function show(string $id)
    {
        // Kiểm tra tồn tại NẰM NGOÀI cache: 404 không bao giờ được cache, và một
        // exception ném từ trong closure sẽ khiến CacheService chạy resolver lần hai.
        $document = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($document === null) {
            return $this->respondNotFound('Không tìm thấy phiếu xuất linh kiện.');
        }

        // TTL 'search' (120s): phiếu xuất linh kiện là chứng từ đang lưu thông.
        // `status` nằm trong khoá để bước Post/Cancel đổi ngay entry; Post còn đi qua
        // mutateBalance() nên `stock:version` cũng tự bump.
        $payload = $this->cachedReadPayload(
            'warehouse:component-issue:read',
            ['id' => $id, 'status' => (string) ($document->status ?? '')],
            fn (): array => $this->buildShowPayload($id, $document),
            'search',
            'stock:version',
        );

        return $this->respondSuccess($payload);
    }

    /**
     * Phần chạy SQL của show(), tách ra để cache được (mảng thuần).
     *
     * @return array<string, mixed>
     */
    private function buildShowPayload(string $id, object $document): array
    {
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

        return [
            'document' => $document,
            'lines' => $lines,
        ];
    }
}
