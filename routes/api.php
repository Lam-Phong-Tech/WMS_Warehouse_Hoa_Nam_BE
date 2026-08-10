<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', [HealthController::class, 'show'])->name('api.health');

// Route nghiệp vụ thật sự nằm trong Routes/api.php của từng module (nwidart/laravel-modules),
// tự đăng ký qua <Module>ServiceProvider::boot().
