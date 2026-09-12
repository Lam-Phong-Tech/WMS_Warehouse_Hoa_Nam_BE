<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kho tạm dừng = ĐÓNG BĂNG GHI, KHÔNG đóng băng đọc.
 *
 * Nghiệp vụ (BA 07/09/2026, màn Hệ thống → Cấu hình kho): Hoa Nam vận hành đúng 01 kho
 * tổng. Khi Super Admin chuyển kho sang "Ngừng hoạt động", toàn bộ nghiệp vụ GHI/THAY ĐỔI
 * dữ liệu kho phải bị khoá, còn View/Search/Report/Audit vẫn chạy bình thường.
 *
 * VÌ SAO LÀ MỘT MIDDLEWARE CHUNG, KHÔNG PHẢI KIỂM TRA RẢI TỪNG CONTROLLER:
 * trước đợt này chỉ có ScanController và MiniAppInboundStagingController tự kiểm — nghĩa
 * là 2/~170 route ghi. Kho "tạm dừng" mà vẫn Post được phiếu nhập/xuất thì trạng thái đó
 * không có nghĩa gì. Kiểm ở từng controller cũng không đóng được lỗ: mỗi route ghi mới
 * thêm sau này lại là một chỗ có thể quên.
 *
 * FAIL-CLOSED CÓ CHỦ Ý: mọi phương thức ghi đều bị chặn TRỪ danh sách cho phép dưới đây.
 * Route ghi mới sinh ra sẽ tự động bị đóng băng theo; muốn nó chạy được lúc kho tạm dừng
 * thì phải khai tường minh vào đây — quyết định đó nên là quyết định có ý thức.
 *
 * ĐĂNG KÝ Ở NHÓM `api`, KHÔNG PHẢI GLOBAL: middleware global chạy TRƯỚC bước khớp route
 * nên `$request->route()` luôn null (xem ghi chú của ApiRateLimit trong bootstrap/app.php),
 * mà danh sách cho phép ở đây so theo `Route::uri()` để không phụ thuộc giá trị {id} thật.
 */
class EnsureWarehouseOperational
{
    /**
     * Phương thức KHÔNG đổi dữ liệu — luôn cho qua. Đây chính là vế "vẫn cho phép
     * View/Search/Report/Audit" của yêu cầu: cả bốn thứ đó đều là GET.
     */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Tiền tố URI được GHI kể cả khi kho tạm dừng.
     *
     * Nguyên tắc chọn: đây là những thứ KHÔNG phải "dữ liệu kho" — quản trị hệ thống,
     * phiên đăng nhập, hộp thư cá nhân, và mặt công khai của App PV. Đóng băng chúng
     * theo sẽ khoá luôn cả đường quản trị: không đăng nhập được thì cũng không ai vào
     * bật kho lại được.
     */
    private const ALLOWED_PREFIXES = [
        // Phiên đăng nhập + đổi/khôi phục mật khẩu.
        'api/v1/auth/',
        // IAM: tài khoản, vai trò, phòng ban.
        'api/v1/users',
        'api/v1/roles',
        'api/v1/departments',
        // Hộp thư thông báo cá nhân (tự scope theo user_id).
        'api/v1/notifications',
        // Bắt tay websocket của Reverb. Có HAI đường đăng ký: `api/v1/broadcasting/auth`
        // và `broadcasting/auth` trần (Laravel tự khai) — phải cho qua cả hai, chặn một
        // cái là kênh realtime đứt trong suốt thời gian kho tạm dừng, trong khi thông
        // báo là thứ ĐỌC và Q03 nói rõ đọc vẫn phải chạy.
        'api/v1/broadcasting/',
        'broadcasting/',
        // Mặt ẩn danh của App PV (gửi yêu cầu báo giá) — khách hàng không liên quan
        // tới việc kho đang tạm dừng.
        'api/v1/public/',
    ];

    /**
     * URI được GHI kể cả khi kho tạm dừng, khớp CHÍNH XÁC theo `Route::uri()`.
     *
     * `api/v1/warehouses/{id}` là cửa DUY NHẤT để bật kho lại (Q04). Khoá nốt nó thì
     * "tạm dừng" trở thành trạng thái một chiều, chỉ sửa được bằng tay dưới database —
     * đó là lỗi thiết kế, không phải mức bảo vệ cao hơn.
     */
    private const ALLOWED_URIS = [
        'api/v1/warehouses/{id}',
        /*
         * Xuất báo cáo bất đồng bộ. Đây là POST nhưng thuộc vế "vẫn cho phép ...
         * Report" của yêu cầu: nó chỉ tạo một bản ghi JOB XUẤT (report_export_jobs) +
         * audit, không đụng một dòng dữ liệu kho nào.
         *
         * Không cho qua thì cùng một việc "xuất báo cáo" chạy được ở đường đồng bộ
         * (GET reports/*\/export-excel là GET nên không bị đóng băng) mà chết ở đường
         * job nền — người dùng thấy nút Xuất lúc được lúc không, tuỳ báo cáo.
         */
        'api/v1/reports/export',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array(strtoupper($request->method()), self::READ_METHODS, true)) {
            return $next($request);
        }

        if ($this->isAllowedWhilePaused($request)) {
            return $next($request);
        }

        if (! $this->isPaused()) {
            return $next($request);
        }

        // Hoa Nam chỉ có đúng 01 kho tổng. Lấy chính dòng đang tạm dừng để FE Mini App
        // có trạng thái + lý do thật, không phải tự suy đoán từ message.
        $warehouse = DB::table('warehouses')
            ->where('status', '<>', 'ACTIVE')
            ->orderBy('created_at')
            ->first(['id', 'code', 'name', 'status', 'suspension_reason']);

        // 409 (không phải 422): dữ liệu gửi lên không sai, chỉ là TRẠNG THÁI hệ thống
        // hiện không nhận thao tác ghi — cùng lớp ngữ nghĩa với ScanController.
        ApiError::abort(
            409,
            'WAREHOUSE_INACTIVE',
            'Kho đang tạm dừng hoạt động nên mọi thao tác ghi bị khoá. Vẫn xem, tra cứu, '
            .'xuất báo cáo và tra nhật ký được. Liên hệ quản trị để mở lại kho.',
            [
                'blocked_method' => strtoupper($request->method()),
                'warehouse_id' => $warehouse?->id !== null ? (string) $warehouse->id : null,
                'warehouse_status' => $warehouse?->status !== null ? (string) $warehouse->status : 'INACTIVE',
                'is_suspended' => true,
                'suspension_reason' => $warehouse?->suspension_reason,
            ],
        );
    }

    private function isAllowedWhilePaused(Request $request): bool
    {
        $uri = (string) ($request->route()?->uri() ?? '');

        if ($uri !== '' && in_array($uri, self::ALLOWED_URIS, true)) {
            return true;
        }

        // So trên `$request->path()` chứ không chỉ trên route uri: request chưa khớp
        // được route (404/405) vẫn phải phân loại đúng, và path là thứ luôn có.
        $path = trim($request->path(), '/');

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix) || str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Tạm dừng" = ĐÃ CÓ kho nhưng KHÔNG kho nào ACTIVE.
     *
     * Phân biệt với "chưa có kho nào" là bắt buộc, không phải chi li: Hoa Nam chỉ có
     * 01 kho và nó được tạo qua POST /warehouses — một route GHI. Nếu bảng rỗng cũng
     * bị coi là tạm dừng thì hệ thống mới dựng sẽ tự khoá luôn đường tạo kho đầu tiên,
     * và không có thao tác nào trên API gỡ ra được (vòng luẩn quẩn: phải có kho ACTIVE
     * mới tạo được kho).
     *
     * Không cache: truy vấn chỉ chạy trên request GHI (hiếm hơn đọc rất nhiều) và đánh
     * vào cột có index. Cache lại thì mở ra cửa sổ mà kho đã tạm dừng nhưng vẫn ghi
     * được — đúng thứ middleware này sinh ra để chặn.
     */
    private function isPaused(): bool
    {
        $counts = DB::table('warehouses')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_total")
            ->first();

        $total = (int) ($counts->total ?? 0);
        $active = (int) ($counts->active_total ?? 0);

        return $total > 0 && $active === 0;
    }
}
