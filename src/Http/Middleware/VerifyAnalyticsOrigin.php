<?php

namespace SergeyBruhin\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A mismatched Origin/Referer gets a quiet 202, identical to a genuine
 * accepted hit — never a 403. A prober that gets a different status code for
 * "blocked" than for "accepted" has a working oracle to route around; one
 * that always sees 202 doesn't. Requests missing both headers entirely (some
 * privacy-hardened browsers/extensions strip them even for same-origin
 * requests) are let through rather than penalized for something outside the
 * visitor's control.
 */
class VerifyAnalyticsOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $originHost = $this->hostFrom($request->header('Origin')) ?? $this->hostFrom($request->header('Referer'));

        if ($expectedHost !== null && $originHost !== null && ! $this->hostsMatch($expectedHost, $originHost)) {
            return response()->noContent(202);
        }

        return $next($request);
    }

    protected function hostFrom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return parse_url($value, PHP_URL_HOST) ?: null;
    }

    protected function hostsMatch(string $expected, string $actual): bool
    {
        return strcasecmp($expected, $actual) === 0;
    }
}
