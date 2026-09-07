<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ngưỡng ghi request chậm (ms) — xem App\Http\Middleware\SlowRequestLog
    |--------------------------------------------------------------------------
    |
    | Request có tổng thời gian >= ngưỡng được ghi ở mức WARNING kèm phân rã:
    | route, số câu SQL, thời gian SQL, hit/miss cache, thời gian chờ lock
    | stampede, bộ nhớ đỉnh, request_id. 0 = tắt. Không ghi tham số/body.
    |
    */

    'slow_request_ms' => (int) env('SLOW_REQUEST_MS', 1000),

];
