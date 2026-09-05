<?php

namespace SergeyBruhin\Analytics\Drivers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SergeyBruhin\Analytics\Contracts\AnalyticsStore;
use SergeyBruhin\Analytics\Drivers\Postgres\PostgresStore;

/**
 * Resolves the configured storage driver, same shape as Laravel's own
 * CacheManager/QueueManager. A future ClickHouse (or any other) driver plugs
 * in via extend() from this package's own service provider in a later
 * release, or from a host app's provider for a custom backend — without
 * touching CollectController, RecordAnalyticsHitJob, or the frontend tracker.
 */
class DriverManager
{
    /** @var array<string, \Closure(Container): AnalyticsStore> */
    protected array $customCreators = [];

    /** @var array<string, AnalyticsStore> */
    protected array $resolved = [];

    public function __construct(protected Container $app) {}

    public function driver(?string $name = null): AnalyticsStore
    {
        $name ??= $this->app['config']->get('laravel-analytics.driver', 'postgres');

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * @param  \Closure(Container): AnalyticsStore  $callback
     */
    public function extend(string $name, \Closure $callback): void
    {
        $this->customCreators[$name] = $callback;
        unset($this->resolved[$name]);
    }

    protected function resolve(string $name): AnalyticsStore
    {
        if (isset($this->customCreators[$name])) {
            return $this->customCreators[$name]($this->app);
        }

        return match ($name) {
            'postgres' => $this->createPostgresDriver(),
            default => throw new InvalidArgumentException("Analytics driver [{$name}] is not defined. Register it via DriverManager::extend()."),
        };
    }

    protected function createPostgresDriver(): AnalyticsStore
    {
        $connection = $this->app['config']->get('laravel-analytics.drivers.postgres.connection', 'analytics');

        return new PostgresStore($connection);
    }
}
