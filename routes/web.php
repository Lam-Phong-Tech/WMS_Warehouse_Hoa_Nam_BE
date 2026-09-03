<?php

use App\Http\Controllers\RootPingController;
use Illuminate\Support\Facades\Route;

// withoutMiddleware('web') skips session start / CSRF / error-sharing for this
// static health-check text response -- it reads no session and renders no
// form, so paying that per-request overhead bought nothing. Left the route
// in web.php (not moved to api.php) since it isn't a JSON API endpoint.
//
// Dùng controller (__invoke) thay cho Closure: Closure không serialize được nên
// một route Closure duy nhất cũng làm `php artisan route:cache` thất bại cho
// TOÀN BỘ tập route. Nội dung/đường dẫn/middleware giữ nguyên. Xem
// App\Http\Controllers\RootPingController.
Route::get('/', RootPingController::class)->withoutMiddleware('web');
