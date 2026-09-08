<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Http\Controllers\CurrentBaselineComponentFlowController;

/**
 * Nghiệp vụ 04: chỉ hồ sơ bảo hành "Đang kiểm tra" (CHECKING) hoặc "Đang sửa chữa"
 * (REPAIRING) mới được xuất linh kiện, trong khi hồ sơ vừa tạo luôn ở RECEIVED.
 *
 * Mọi fixture dựng phiếu xuất linh kiện phải đi qua bước này, nếu không sẽ nhận
 * 422 WARRANTY_CASE_STATUS_NOT_ALLOWED ngay ở bước tạo phiếu.
 *
 * @see CurrentBaselineComponentFlowController::CASE_STATUSES_ALLOWING_ISSUE
 */
trait OpensWarrantyCaseForComponentIssue
{
    /**
     * Chuyển hồ sơ sang CHECKING và trả lại chính `caseId` để gọi lồng được vào
     * payload (`'warranty_case_id' => $this->moveCaseToChecking(...)`).
     *
     * @param  array<string, string>  $headers
     */
    private function moveCaseToChecking(array $headers, string $caseId): string
    {
        // Đọc version từ DB thay vì đoán "vừa tạo nên version = 1": có fixture đã
        // PATCH ghi chú chẩn đoán trước đó, version không còn là 1.
        $version = (int) DB::table('warranty_case')->where('warranty_case_id', $caseId)->value('version');

        $this->patchJson("/api/v1/warranty-cases/{$caseId}", [
            'status' => 'CHECKING',
        ], array_merge($headers, [
            'If-Match' => (string) $version,
            'Idempotency-Key' => 'case-checking-'.(string) Str::uuid(),
        ]))->assertOk();

        return $caseId;
    }
}
