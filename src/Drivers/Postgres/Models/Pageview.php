<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pageview extends Model
{
    protected $connection = 'analytics';

    public $table = 'pageviews';

    public $timestamps = false;

    // Populated only from arrays the job/store already built, never
    // directly from raw request input — safe to leave unguarded.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }
}
