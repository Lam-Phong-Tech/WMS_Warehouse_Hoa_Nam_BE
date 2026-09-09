<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;

/**
 * Bộ lọc "Sắp xếp theo" của các màn danh sách phiếu (IN-01 / OUT-01 / WAR-01).
 *
 * Hợp đồng: `?sort_order=desc` = Mới nhất (phiếu được TẠO hoặc SỬA gần nhất lên
 * đầu), `?sort_order=asc` = Cũ nhất. KHÔNG gửi tham số thì giữ nguyên thứ tự
 * mặc định cũ của từng endpoint (created_at desc) — client cũ và Mini App không
 * đổi hành vi.
 *
 * Khoá sắp xếp là COALESCE(updated_at, created_at) chứ không phải created_at:
 * "Mới nhất" theo nghĩa người dùng là lần đụng vào phiếu gần nhất (tạo, sửa
 * thông tin, quét, Post đều bump updated_at); COALESCE che các dòng seed/legacy
 * chưa có updated_at.
 */
final class ListSortOrder
{
    public const NEWEST_FIRST = 'desc';

    public const OLDEST_FIRST = 'asc';

    /**
     * Đọc `sort_order` từ query string. Trả null khi không gửi (giữ hành vi cũ),
     * 'asc'/'desc' khi hợp lệ; giá trị lạ trả 422 để FE không tưởng nhầm mình
     * đang được sắp xếp trong khi BE lặng lẽ bỏ qua.
     */
    public static function fromRequest(Request $request): ?string
    {
        if (! $request->filled('sort_order')) {
            return null;
        }

        $value = strtolower(trim((string) $request->input('sort_order')));
        if (! in_array($value, [self::NEWEST_FIRST, self::OLDEST_FIRST], true)) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'sort_order chỉ nhận asc/desc.');
        }

        return $value;
    }

    /**
     * Áp thứ tự "tạo/sửa gần nhất" lên query. Tie-break bằng created_at rồi khoá
     * chính để phân trang ổn định khi dữ liệu seed có timestamp trùng nhau
     * (thiếu tie-break thì cùng một phiếu có thể xuất hiện ở hai trang).
     *
     * $direction đã qua whitelist ở fromRequest nên nội suy vào orderByRaw an toàn.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyRecency($query, string $direction, string $primaryKey = 'id'): void
    {
        $query->orderByRaw("COALESCE(updated_at, created_at) {$direction}")
            ->orderBy('created_at', $direction)
            ->orderBy($primaryKey, $direction);
    }
}
