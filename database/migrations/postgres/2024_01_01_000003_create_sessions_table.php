<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('visitor_id')->index();
            $table->string('site')->index();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->jsonb('click_ids')->nullable();
            $table->text('landing_url')->nullable();
            $table->text('exit_url')->nullable();
            $table->text('referrer')->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->ipAddress('ip_truncated')->nullable();

            $table->foreign('visitor_id')->references('id')->on('visitors')->cascadeOnDelete();
            $table->index(['visitor_id', 'started_at']);
            $table->index(['site', 'started_at']);
        });

        // A partial index isn't expressible through Laravel's schema grammar
        // (it has no cross-database equivalent) — reporting queries almost
        // always exclude bot traffic, so this keeps that filter cheap as the
        // table grows.
        $schema = config('database.connections.analytics.search_path', 'analytics');
        DB::connection('analytics')->statement(
            "CREATE INDEX sessions_site_started_at_not_bot_idx ON \"{$schema}\".\"sessions\" (site, started_at) WHERE is_bot = false"
        );
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('sessions');
    }
};
