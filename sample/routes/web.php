<?php

use Illuminate\Support\Facades\Route;
use Sample\App\Http\Controllers\HomeController;

Route::middleware('requestlog')->group(function () {
    Route::get('/', [HomeController::class, 'index']);
    Route::post('/post', [HomeController::class, 'post']);
    Route::post('/upload', [HomeController::class, 'upload']);
});
