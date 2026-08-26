<?php

use Illuminate\Support\Facades\Route;

// withoutMiddleware('web') skips session start / CSRF / error-sharing for this
// static health-check text response -- it reads no session and renders no
// form, so paying that per-request overhead bought nothing. Left the route
// in web.php (not moved to api.php) since it isn't a JSON API endpoint.
Route::get('/', function () {
    return response('WMS Hoa Nam Backend OK', 200);
})->withoutMiddleware('web');
