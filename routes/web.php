<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web entry
|--------------------------------------------------------------------------
|
| Same /v1/* route format as the API is registered in bootstrap/app.php
| (both /v1 and /api/v1). Keep this file for non-API web pages only.
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => 'Welcome to Payhankey API',
        'version' => '5.60.40',
        'status' => 'online',
        'docs' => [
            'v1' => url('/v1'),
            'api_v1' => url('/api/v1'),
        ],
    ]);
});
