<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    protected $connection = 'analytics';

    public $table = 'visitors';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    // The primary key is a client-generated UUID set explicitly at creation
    // (see PostgresStore::upsertVisitor), not an auto-increment value — it
    // must be mass-assignable. Safe to leave fully unguarded: this model is
    // only ever populated from arrays the job/store already built, never
    // directly from raw request input.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_click_ids' => 'array',
            'traits' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'visitor_id');
    }

    public function pageviews(): HasMany
    {
        return $this->hasMany(Pageview::class, 'visitor_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'visitor_id');
    }
}
