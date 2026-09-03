<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Điểm chạm tối giản ở "/" — trả đúng một chuỗi tĩnh cho health-check hạ tầng
 * (load balancer, uptime monitor), không đọc session, không render form.
 *
 * Vì sao là controller chứ không phải Closure trong routes/web.php: `php artisan
 * route:cache` TỪ CHỐI toàn bộ tập route nếu có bất kỳ route nào dùng Closure
 * (Closure không serialize được). Đây là route Closure DUY NHẤT của dự án; đưa
 * về controller mở khoá route cache cho toàn bộ 342 route còn lại — mỗi request
 * sau đó bỏ được bước dựng lại bảng định tuyến. Đường dẫn, nội dung trả về và
 * `withoutMiddleware('web')` giữ NGUYÊN như bản Closure cũ.
 */
class RootPingController extends Controller
{
    public function __invoke(): Response
    {
        return response('WMS Hoa Nam Backend OK', 200);
    }
}
