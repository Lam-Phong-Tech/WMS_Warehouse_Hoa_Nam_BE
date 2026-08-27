<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\User;

/**
 * SCR-IAM-02 — không khuếch đại đặc quyền (no privilege amplification).
 *
 * `iam.role.manage` và `iam.user.manage` trước đây cho phép gán BẤT KỲ quyền nào
 * trong danh mục, kể cả quyền mà chính người thao tác không có, và kể cả cho
 * role mà chính họ đang giữ. Một WAREHOUSE_MANAGER vì thế có thể tự nâng mình
 * lên toàn quyền chỉ bằng màn ma trận IAM-01. `PreventSelfRoleAssignment`
 * (SCR-IAM-01) chỉ bịt đường "tự đổi role của mình", không bịt đường này.
 *
 * Quy tắc: người thao tác chỉ được CẤP hoặc THU HỒI những quyền chính họ đang
 * có. Quyền nằm ngoài tập của họ mà role/tài khoản đích đang giữ thì vẫn được
 * giữ nguyên — chỉ phần THAY ĐỔI (thêm ∪ bớt) mới bị soi. Nhờ vậy một quản trị
 * viên cấp dưới vẫn lưu được ma trận mà không vô tình thu hồi quyền cao hơn
 * mình, và cũng không cấp thêm được thứ mình không có.
 *
 * SUPER_ADMIN bỏ qua toàn bộ kiểm tra này.
 */
final class PermissionDelegation
{
    private function __construct() {}

    /**
     * Soi phần thay đổi giữa tập quyền cũ và mới của một role.
     *
     * @param  array<int, string>  $beforePermissionIds
     * @param  array<int, string>  $afterPermissionIds
     */
    public static function assertMayReplaceRolePermissions(
        ?User $actor,
        array $beforePermissionIds,
        array $afterPermissionIds,
    ): void {
        $added = array_diff($afterPermissionIds, $beforePermissionIds);
        $removed = array_diff($beforePermissionIds, $afterPermissionIds);

        self::assertMayDelegatePermissionIds($actor, array_values(array_unique(array_merge($added, $removed))));
    }

    /**
     * @param  array<int, string>  $permissionIds
     */
    public static function assertMayDelegatePermissionIds(?User $actor, array $permissionIds): void
    {
        if ($permissionIds === [] || $actor === null || $actor->isSuperAdmin()) {
            return;
        }

        $keys = Permission::query()
            ->whereIn('permission_id', $permissionIds)
            ->pluck('permission_key')
            ->all();

        self::assertMayDelegate($actor, $keys);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    public static function assertMayDelegate(?User $actor, array $permissionKeys): void
    {
        if ($permissionKeys === [] || $actor === null || $actor->isSuperAdmin()) {
            return;
        }

        $own = $actor->permissionCodes();
        $beyond = array_values(array_unique(array_filter(
            $permissionKeys,
            static fn (string $key): bool => ! in_array($key, $own, true),
        )));

        if ($beyond === []) {
            return;
        }

        sort($beyond);

        // Mã quyền đi qua $data (không bị Việt hoá), KHÔNG qua message/details:
        // UserFacingMessageLocalizer::stillLooksEnglish() bắt các từ như
        // "warehouse"/"status"/"reason" — vốn là một phần của chính mã quyền
        // (warehouse.manage, ...) — nên nhét mã vào message sẽ khiến toàn bộ câu
        // tiếng Việt bị thay bằng câu lỗi chung fail-closed.
        ApiError::abort(
            403,
            'PERMISSION_DELEGATION_DENIED',
            'Bạn chỉ được cấp hoặc thu hồi những quyền mà chính bạn đang có. '
            .'Yêu cầu này đụng tới '.count($beyond).' quyền nằm ngoài phạm vi của bạn.',
            [],
            ['permissions_beyond_actor_scope' => $beyond],
        );
    }
}
