<?php

namespace SergeyBruhin\Analytics\Http\Controllers;

use Illuminate\Routing\Controller;
use SergeyBruhin\Analytics\Http\Requests\CollectRequest;
use SergeyBruhin\Analytics\Jobs\RecordAnalyticsHitJob;
use Symfony\Component\HttpFoundation\Response;

class CollectController extends Controller
{
    public function __invoke(CollectRequest $request): Response
    {
        // The site is always the host's own configured key, never whatever a
        // client sends — otherwise any visitor could write rows under a
        // different project's `site` value in a shared external database.
        $site = (string) config('laravel-analytics.site');

        RecordAnalyticsHitJob::dispatch(
            payload: $request->validated(),
            site: $site,
            ip: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        )->onConnection(config('laravel-analytics.queue_connection', 'redis_analytics'));

        return response()->noContent(202);
    }
}
