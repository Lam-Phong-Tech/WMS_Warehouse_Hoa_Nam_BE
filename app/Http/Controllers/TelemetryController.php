<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

/**
 * RUM — nhận Web Vitals ĐO TRÊN MÁY NGƯỜI DÙNG THẬT.
 *
 * VÌ SAO CÓ ENDPOINT NÀY
 * docs/perf/F5_INP_2026-09-07.md §4.5 ghi rõ: "Chưa có RUM. Mọi số ở đây là
 * phòng thí nghiệm, một máy." Bàn đo (scripts/perf/bench bên FE) trả lời được
 * "thay đổi này có tác dụng không" trên MỘT cấu hình; nó không trả lời được
 * "người dùng thật trong kho đang thấy gì" — máy yếu, wifi kho, 40 tab, màn
 * hình 1366×768. Hai lớp đó KHÔNG thay thế nhau và không được trộn số với nhau.
 *
 * VÌ SAO KHÔNG THÊM BẢNG
 * Bộ test ERD parity (ErdPhysicalSchemaParityTest…) khoá lược đồ vật lý theo
 * ERD; thêm một bảng telemetry sẽ làm chúng đỏ và kéo theo cả một vòng cập nhật
 * tài liệu ERD cho dữ liệu vận hành thuần tuý. Số liệu RUM là dữ liệu ĐẾM ĐƯỢC,
 * không phải dữ liệu nghiệp vụ: nó sống trong Valkey dưới dạng bộ đếm cộng dồn
 * + phân bố theo ngưỡng, đủ để tính tỉ lệ "tốt/cần cải thiện/kém" và trung bình
 * mà không giữ lại một dòng nào cho mỗi lượt xem trang.
 *
 * VÌ SAO KHÔNG YÊU CẦU ĐĂNG NHẬP
 * Beacon được bắn lúc `visibilitychange -> hidden`, tức thường là lúc người dùng
 * đóng tab hoặc đăng xuất — access token trong bộ nhớ có thể đã không còn. Bắt
 * buộc auth ở đây đồng nghĩa với việc mất đúng những phiên đáng quan tâm nhất.
 * Bù lại: endpoint chỉ ghi bộ đếm, không đọc/ghi dữ liệu nghiệp vụ, chặn theo
 * `throttle`, và validate chặt để không ai bơm được khoá cache tuỳ ý vào Valkey.
 */
class TelemetryController extends Controller
{
    use ApiResponse;

    /** Chỉ ba chỉ số Core Web Vitals — không nhận tên tuỳ ý. */
    private const METRICS = ['LCP', 'INP', 'CLS'];

    /**
     * Ngưỡng "tốt / cần cải thiện" của Google cho từng chỉ số.
     * CLS là tỉ lệ (không đơn vị), LCP/INP tính bằng mili giây.
     */
    private const THRESHOLDS = [
        'LCP' => [2500, 4000],
        'INP' => [200, 500],
        'CLS' => [0.1, 0.25],
    ];

    /** Trần giá trị nhận vào — số vô lý chỉ làm hỏng trung bình. */
    private const MAX_VALUE = [
        'LCP' => 600000,
        'INP' => 600000,
        'CLS' => 100,
    ];

    /** Bộ đếm sống 7 ngày: đủ để so hai lần deploy liền nhau. */
    private const TTL_SECONDS = 604800;

    #[OA\Post(
        path: '/api/v1/telemetry/vitals',
        operationId: 'telemetry_vitals',
        summary: 'Nhận Core Web Vitals đo trên trình duyệt người dùng thật (RUM)',
        description: 'Chỉ ghi bộ đếm cộng dồn trong cache (không bảng, không dữ liệu cá nhân). Không yêu cầu đăng nhập vì beacon được bắn lúc tab bị ẩn/đóng; chặn lạm dụng bằng throttle. Trả 202 với thân rỗng để beacon kết thúc nhanh nhất có thể.',
        tags: ['13. Telemetry'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['build_id', 'route', 'metrics'],
            properties: [
                new OA\Property(property: 'build_id', type: 'string', maxLength: 64, description: 'Định danh bản build của FE (__APP_BUILD_ID__)'),
                new OA\Property(property: 'route', type: 'string', maxLength: 120, description: 'Mẫu route, ĐÃ bỏ tham số động (vd /reports/traceability)'),
                new OA\Property(property: 'metrics', type: 'object', description: 'Map tên chỉ số -> giá trị', additionalProperties: new OA\AdditionalProperties(type: 'number')),
            ]
        )),
        responses: [
            new OA\Response(response: 202, description: 'Đã ghi nhận'),
            new OA\Response(response: 422, description: 'BUSINESS_RULE_ERROR — payload không hợp lệ'),
            new OA\Response(response: 429, description: 'Vượt hạn mức throttle'),
        ]
    )]
    public function vitals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'build_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            // `route` đi thẳng vào khoá cache nên phải là MẪU route đã chuẩn hoá,
            // không phải URL thật. Hai luật, hai mục đích khác nhau:
            //  · regex thứ nhất giới hạn bộ ký tự -> không ai bơm được khoá cache
            //    tuỳ ý (dấu :, *, khoảng trắng… đều bị loại);
            //  · regex thứ hai LOẠI đoạn định danh động (UUID, chuỗi hex/số dài)
            //    -> id hàng hoá/người dùng không lọt vào telemetry, và số liệu
            //    không bị vỡ vụn thành mỗi bản ghi một khoá.
            'route' => [
                'required', 'string', 'max:120',
                'regex:#^/[a-z0-9/_-]*$#i',
                'not_regex:#(^|/)([0-9a-f-]{8,}|\d{6,})(/|$)#i',
            ],
            'metrics' => ['required', 'array', 'min:1', 'max:3'],
            'metrics.*' => ['numeric'],
        ]);

        $day = now()->format('Y-m-d');
        foreach ($validated['metrics'] as $name => $value) {
            $metric = strtoupper((string) $name);
            if (! in_array($metric, self::METRICS, true)) {
                continue;
            }
            $value = (float) $value;
            if ($value < 0 || $value > self::MAX_VALUE[$metric]) {
                continue;
            }

            $prefix = "wms:rum:{$day}:{$validated['build_id']}:{$validated['route']}:{$metric}";
            $this->bump("{$prefix}:count", 1);
            // Nhân 1000 rồi làm tròn: bộ đếm Redis là số nguyên, còn CLS là số
            // thập phân nhỏ. Chia lại lúc đọc.
            $this->bump("{$prefix}:sum_milli", (int) round($value * 1000));
            $this->bump("{$prefix}:{$this->bucketOf($metric, $value)}", 1);
        }

        // Thân rỗng: beacon không đọc phản hồi, mọi byte trả về đều là lãng phí.
        return $this->respondSuccess(null, null, 202);
    }

    /** Tăng một bộ đếm và bảo đảm nó có TTL (khoá RUM không được sống mãi). */
    private function bump(string $key, int $by): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + $by, self::TTL_SECONDS);
    }

    /** Phân loại theo ngưỡng Web Vitals — cho phép đọc tỉ lệ mà không cần histogram. */
    private function bucketOf(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric];
        if ($value <= $good) {
            return 'good';
        }

        return $value <= $poor ? 'needs_improvement' : 'poor';
    }
}
