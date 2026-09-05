<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres\Models;

use Illuminate\Database\Eloquent\Model;

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
}
