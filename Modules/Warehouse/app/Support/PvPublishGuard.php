<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\DenyAuditRecorder;

/**
 * WWHN-SEC-09 (BFLA): công tắc `published_to_app` quyết định SKU có xuất hiện
 * trên bề mặt CÔNG KHAI (App PV + storefront /public) hay không, và Annex C đã
 * tách nó thành quyền riêng `pv.publish` (route PATCH /pv/products/{id}/publish
 * ghi rõ "không nhận |sku.manage"). Nhưng field này đồng thời nằm trong rules
 * của 3 controller ghi SKU tổng quát (ProductController, SkuGovernanceController,
 * CurrentBaselineSkuController) vốn chỉ đòi `sku.manage` — tức là ai có
 * sku.manage vẫn publish/unpublish được bằng generic update, làm phép tách quyền
 * thành hình thức.
 *
 * Guard này đóng đường vòng đó tại MỌI điểm ghi generic: đổi GIÁ TRỊ của
 * published_to_app đòi hỏi pv.publish; gửi lại đúng giá trị hiện tại (form FE
 * echo nguyên trạng thái) thì không sao.
 */
class PvPublishGuard
{
    public const PERMISSION = 'pv.publish';

    /**
     * @param  array<string, mixed>  $payload  payload đã validate của create/update SKU
     * @param  string|null  $skuId  null = đường create (chưa có giá trị hiện tại)
     */
    public static function assertPayload(array $payload, ?string $skuId): void
    {
        if (! array_key_exists('published_to_app', $payload)) {
            return;
        }

        $requested = filter_var($payload['published_to_app'], FILTER_VALIDATE_BOOL);

        if ($skuId === null) {
            // Create: giá trị mặc định của cột là false, nên chỉ publish-ngay-khi-tạo
            // mới là hành vi cần quyền.
            if ($requested === false) {
                return;
            }
        } else {
            // `published_to_app` không nằm trên bảng gốc `skus` mà trên các VIEW
            // tương thích (`sku`/`products`, đồng bộ qua trigger). Đọc qua view
            // `products` — key `id` trùng UUID với `sku_id` của mọi đường ghi.
            $current = DB::table('products')->where('id', $skuId)->value('published_to_app');
            if ($current !== null && filter_var($current, FILTER_VALIDATE_BOOL) === $requested) {
                return; // không đổi giá trị — form gửi lại nguyên trạng
            }
        }

        $user = auth('api')->user();
        if (is_object($user) && method_exists($user, 'hasPermission') && $user->hasPermission(self::PERMISSION)) {
            return;
        }

        DenyAuditRecorder::permissionDenied(request(), self::PERMISSION, is_object($user) ? ($user->user_id ?? null) : null);
        ApiError::abort(403, 'ACCESS_DENIED', 'Đổi trạng thái hiển thị App PV (published_to_app) cần quyền pv.publish.');
    }
}
