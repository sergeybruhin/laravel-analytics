<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Pageview;

/**
 * Read-only by design — this data is written exclusively through the
 * collect endpoint's queue job; Nova is a reporting surface on top of it,
 * not an editor for it.
 */
class AnalyticsVisit extends Resource
{
    public static $model = Pageview::class;

    public static $title = 'path';

    public static $search = ['path', 'site'];

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Site')->sortable(),
            Text::make('Path')->sortable(),
            Text::make('Referrer'),
            Text::make('Title'),
            Number::make('Duration (ms)', 'duration_ms'),
            Text::make('Visitor', 'visitor_id')->sortable(),
            Text::make('Session', 'session_id')->sortable(),
            Boolean::make('Bot', fn (Pageview $pageview) => (bool) $pageview->session?->is_bot),
            Text::make('Device', fn (Pageview $pageview) => $pageview->session?->device_type),
            Text::make('UTM Source', fn (Pageview $pageview) => $pageview->session?->utm_source),
            Text::make('UTM Campaign', fn (Pageview $pageview) => $pageview->session?->utm_campaign),
            \Laravel\Nova\Fields\DateTime::make('Created At')->sortable(),
        ];
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }
}
