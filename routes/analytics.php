<?php

use Illuminate\Support\Facades\Route;
use SergeyBruhin\Analytics\Http\Controllers\CollectController;
use SergeyBruhin\Analytics\Http\Middleware\VerifyAnalyticsOrigin;

// `analytics-collect` is a named limiter (AnalyticsServiceProvider), not the
// `throttle:n,m` shorthand. The shorthand keys on the visitor alone, so it
// shares one counter per IP with every other shorthand throttle in the host
// application — this endpoint's traffic would be charged to the host's
// strictest route. See the limiter for the rest.
Route::post('/api/analytics/collect', CollectController::class)
    ->middleware(['throttle:analytics-collect', VerifyAnalyticsOrigin::class])
    ->name('analytics.collect');
