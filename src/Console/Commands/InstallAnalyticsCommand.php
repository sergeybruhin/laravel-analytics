<?php

namespace SergeyBruhin\Analytics\Console\Commands;

use Illuminate\Console\Command;

/**
 * vendor:publish + migrate --database=analytics in one step, so onboarding a
 * new project is a single documented command rather than a sequence someone
 * has to remember exactly — in particular, forgetting --database=analytics
 * on a plain `migrate` would silently create these tables in the app's own
 * default connection instead.
 */
class InstallAnalyticsCommand extends Command
{
    protected $signature = 'analytics:install';

    protected $description = 'Publish the analytics config/migrations and run them against the analytics connection.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'analytics-config']);
        $this->call('vendor:publish', ['--tag' => 'analytics-migrations']);
        $this->call('migrate', ['--database' => 'analytics']);

        $this->info('Analytics installed. Set ANALYTICS_SITE_KEY in .env if you have not already.');

        return self::SUCCESS;
    }
}
