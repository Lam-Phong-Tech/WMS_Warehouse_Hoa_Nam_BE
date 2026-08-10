<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response('WMS Hoa Nam Backend OK', 200);
});
