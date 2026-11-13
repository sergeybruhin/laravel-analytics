<?php

namespace SergeyBruhin\Analytics\Tests;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SergeyBruhin\Analytics\Providers\AnalyticsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CSRF exemption for the collect route is a host-app concern
        // (bootstrap/app.php's validateCsrfTokens(except:) list) — these
        // tests exercise the package's own validation/abuse-mitigation
        // logic, so we assume that exemption is already in place.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function getPackageProviders($app): array
    {
        return [AnalyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('laravel-analytics.site', 'test-site');
        $app['config']->set('queue.default', 'sync');

        // ANALYTICS_TEST_DB_DRIVER=sqlite runs the suite without a Postgres
        // server (the schema is portable apart from one partial index, which
        // the sessions migration only creates on pgsql). Postgres stays the
        // default and the reference: it is what every install runs.
        if (env('ANALYTICS_TEST_DB_DRIVER') === 'sqlite') {
            $app['config']->set('database.connections.analytics', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        $app['config']->set('database.connections.analytics', [
            'driver' => 'pgsql',
            'host' => env('ANALYTICS_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('ANALYTICS_TEST_DB_PORT', '5432'),
            'database' => env('ANALYTICS_TEST_DB_DATABASE', 'analytics_test'),
            'username' => env('ANALYTICS_TEST_DB_USERNAME', 'postgres'),
            'password' => env('ANALYTICS_TEST_DB_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'analytics_test',
            'sslmode' => 'prefer',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/postgres');
    }
}
