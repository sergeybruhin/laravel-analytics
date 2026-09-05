<?php

namespace SergeyBruhin\Analytics\Console\Commands;

use Illuminate\Console\Command;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Event;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Pageview;

/**
 * Register on the host's scheduler (routes/console.php):
 *   Schedule::command('analytics:prune')->daily();
 *
 * Plain DELETE rather than partition-drop — see config/analytics.php's note
 * on why partitioning isn't part of this rollout.
 */
class PruneAnalyticsEventsCommand extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Delete pageviews and events older than the configured retention window.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('laravel-analytics.retention_days', 395));

        $pageviews = Pageview::where('created_at', '<', $cutoff)->delete();
        $events = Event::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$pageviews} pageviews and {$events} events older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
