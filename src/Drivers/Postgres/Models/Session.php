<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    protected $connection = 'analytics';

    public $table = 'sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    // Same rationale as Visitor: the primary key is a client-generated UUID
    // that must be mass-assignable, and this model is never populated
    // directly from raw request input.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'click_ids' => 'array',
            'is_bot' => 'boolean',
            'is_internal' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    public function pageviews(): HasMany
    {
        return $this->hasMany(Pageview::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'session_id');
    }
}
