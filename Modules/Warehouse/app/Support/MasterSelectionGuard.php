<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * BR-AGY-01 ("Agency inactive không chọn cho document mới") và BR-DEF-01..04
 * ("inactive không chọn mới; history không bị đổi").
 *
 * SRS §3 nói rõ backend phải enforce chứ không chỉ ẩn nút UI, nên rule không thể
 * dừng ở bộ lọc `status=ACTIVE` phía FE. Rule `exists:` trong FormRequest chỉ
 * kiểm tra bản ghi tồn tại, vì vậy guard này bổ sung lớp kiểm tra trạng thái.
 *
 * Giữ nguyên giá trị cũ luôn được phép (tham số $currentId): chứng từ/hồ sơ đã
 * trỏ tới một master sau đó bị deactivate vẫn phải sửa được các trường khác mà
 * không bị chặn — "lịch sử giữ nguyên".
 */
class MasterSelectionGuard
{
    public static function assertAgencySelectable(mixed $agencyId, mixed $currentId = null): void
    {
        static::assertSelectable(
            $agencyId,
            $currentId,
            'agencies',
            'agency_id',
            'AGENCY_INACTIVE',
            'Đại lý đã ngừng hoạt động nên không chọn được cho chứng từ mới.'
        );
    }

    public static function assertDefectSelectable(mixed $defectId, mixed $currentId = null): void
    {
        static::assertSelectable(
            $defectId,
            $currentId,
            'defect_catalogs',
            'defect_id',
            'DEFECT_INACTIVE',
            'Bệnh/lỗi đã ngừng sử dụng nên không chọn được cho hồ sơ mới.'
        );
    }

    private static function assertSelectable(
        mixed $id,
        mixed $currentId,
        string $table,
        string $keyColumn,
        string $errorCode,
        string $message
    ): void {
        $id = is_string($id) ? trim($id) : $id;
        if ($id === null || $id === '') {
            return;
        }
        if ($currentId !== null && (string) $currentId === (string) $id) {
            return;
        }

        $status = DB::table($table)->where($keyColumn, $id)->value('status');
        if ($status !== null && strtoupper((string) $status) !== 'ACTIVE') {
            ApiError::abort(422, $errorCode, $message);
        }
    }
}
