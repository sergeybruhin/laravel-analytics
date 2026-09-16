<?php

namespace SergeyBruhin\Analytics\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SergeyBruhin\Analytics\Analytics;
use SergeyBruhin\Analytics\Console\Commands\InstallAnalyticsCommand;
use SergeyBruhin\Analytics\Console\Commands\PruneAnalyticsEventsCommand;
use SergeyBruhin\Analytics\Contracts\AnalyticsStore;
use SergeyBruhin\Analytics\Drivers\DriverManager;
use SergeyBruhin\Analytics\Support\BotDetector;
use SergeyBruhin\Analytics\Support\IpAnonymizer;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A file/key named plainly "analytics" collides with the config
        // many host apps already have for a third-party tool (this one's
        // own config/analytics.php is Yandex Metrika's) — "laravel-analytics"
        // avoids that regardless of which host installs this package.
        $this->mergeConfigFrom(__DIR__.'/../../config/laravel-analytics.php', 'laravel-analytics');

        $this->registerAnalyticsDatabaseConnection();

        $this->app->singleton(BotDetector::class);
        $this->app->singleton(IpAnonymizer::class);
        $this->app->singleton(DriverManager::class);
        $this->app->singleton(AnalyticsStore::class, fn ($app) => $app->make(DriverManager::class)->driver());
        $this->app->singleton(Analytics::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/laravel-analytics.php' => config_path('laravel-analytics.php'),
        ], 'analytics-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations/postgres' => database_path('migrations'),
        ], 'analytics-migrations');

        $this->registerCollectRateLimiter();

        // Own middleware group membership (throttle + origin check) rather
        // than 'api', since this host has no routes/api.php — every /api/*
        // endpoint here already runs under 'web'. Wrapping in 'web' also
        // means the bootstrap/app.php CSRF-except entry actually applies.
        Route::middleware('web')->group(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/analytics.php');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneAnalyticsEventsCommand::class,
                InstallAnalyticsCommand::class,
            ]);
        }

        // Laravel's migrator creates its own bookkeeping "migrations" table
        // before running a single migration *file* — including this
        // package's own schema-creation migration. If the configured
        // search_path schema doesn't exist yet, that bookkeeping-table
        // creation itself fails ("no schema has been selected to create
        // in"), before our migration ever gets a chance to run. Creating
        // the schema here, on every migrate run, sidesteps the ordering
        // problem entirely — CREATE SCHEMA IF NOT EXISTS doesn't depend on
        // search_path, and running it is cheap and idempotent.
        Event::listen(function (MigrationsStarted $event): void {
            $connectionName = $this->app['config']->get('laravel-analytics.drivers.postgres.connection', 'analytics');

            if ($this->app['config']->get("database.connections.{$connectionName}.driver") !== 'pgsql') {
                return;
            }

            $schema = $this->app['config']->get("database.connections.{$connectionName}.search_path", 'analytics');

            DB::connection($connectionName)->statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");
        });
    }

    /**
     * The rate limit for the collect endpoint.
     *
     * A *named* limiter rather than the `throttle:n,m` shorthand, and that is
     * the important part. Laravel keys the shorthand on
     * sha1($domain.'|'.$ip) — the route is not in the key — so every shorthand
     * throttle in a host application shares one counter per visitor IP, and the
     * strictest route in that application silently pays for this endpoint's
     * traffic. Ingesting analytics would then refuse a checkout. A named
     * limiter is keyed on md5($limiterName.$key) and can never do that to its
     * host, whatever the host's routes declare.
     *
     * Registered in boot() so a host that wants different behaviour can define
     * its own `analytics-collect` limiter in a provider of its own — the last
     * registration for a name wins, and application providers boot after
     * package ones.
     */
    private function registerCollectRateLimiter(): void
    {
        RateLimiter::for('analytics-collect', function (Request $request) {
            $config = $this->app['config'];

            $perVisitor = (int) $config->get('laravel-analytics.rate_limit.per_visitor', 300);
            $perIp = (int) $config->get('laravel-analytics.rate_limit.per_ip', 3000);
            $decay = max(1, (int) $config->get('laravel-analytics.rate_limit.decay_minutes', 1));

            // Either figure at zero switches its limit off; both off means the
            // endpoint is deliberately unthrottled here (something in front of
            // the app is doing it instead).
            if ($perVisitor <= 0 && $perIp <= 0) {
                return Limit::none();
            }

            $limits = [];

            if ($perVisitor > 0) {
                // The visitor id the tracker sends. Client-generated, so it is
                // a way of not punishing everyone behind one NAT — never a way
                // of proving who is calling. That is what the backstop is for.
                $visitor = (string) $request->input('visitor_id', '');

                $limits[] = Limit::perMinutes($decay, $perVisitor)
                    ->by('visitor:'.$request->ip().'|'.$visitor);
            }

            if ($perIp > 0) {
                $limits[] = Limit::perMinutes($decay, $perIp)->by('ip:'.$request->ip());
            }

            return $limits;
        });
    }

    /**
     * Registers the "analytics" Laravel DB connection at runtime if the host
     * hasn't defined one itself in config/database.php, so a project can
     * install this package with zero config/database.php edits: unset
     * ANALYTICS_DB_* env vars resolve to the exact same host/database/
     * credentials as the app's own DB connection, isolated only by a
     * dedicated Postgres schema (search_path) — the "same database" mode.
     * Setting ANALYTICS_DB_* is the "external database" mode.
     */
    protected function registerAnalyticsDatabaseConnection(): void
    {
        $key = 'database.connections.analytics';

        if ($this->app['config']->get($key) !== null) {
            return;
        }

        $this->app['config']->set($key, [
            'driver' => 'pgsql',
            'host' => env('ANALYTICS_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('ANALYTICS_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('ANALYTICS_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('ANALYTICS_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('ANALYTICS_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('ANALYTICS_DB_SCHEMA', 'analytics'),
            'sslmode' => env('ANALYTICS_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
        ]);
    }
}
