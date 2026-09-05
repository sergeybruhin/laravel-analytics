<?php

use Illuminate\Support\Facades\Route;
use SergeyBruhin\Analytics\Http\Controllers\CollectController;
use SergeyBruhin\Analytics\Http\Middleware\VerifyAnalyticsOrigin;

Route::post('/api/analytics/collect', CollectController::class)
    ->middleware(['throttle:120,1', VerifyAnalyticsOrigin::class])
    ->name('analytics.collect');
