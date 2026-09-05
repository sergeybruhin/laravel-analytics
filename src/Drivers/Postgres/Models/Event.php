<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $connection = 'analytics';

    public $table = 'events';

    public $timestamps = false;

    // Populated only from arrays the job/store already built, never
    // directly from raw request input — safe to leave unguarded.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'value' => 'decimal:2',
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
