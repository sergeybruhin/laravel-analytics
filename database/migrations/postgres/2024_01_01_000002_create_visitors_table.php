<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('visitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('site')->index();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->text('first_landing_url')->nullable();
            $table->text('first_referrer')->nullable();
            $table->string('first_utm_source')->nullable();
            $table->string('first_utm_medium')->nullable();
            $table->string('first_utm_campaign')->nullable();
            $table->string('first_utm_content')->nullable();
            $table->string('first_utm_term')->nullable();
            $table->jsonb('first_click_ids')->nullable();
            $table->string('external_id')->nullable();
            $table->jsonb('traits')->nullable();

            $table->index(['site', 'first_utm_source', 'first_utm_campaign']);
            $table->unique(['site', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('visitors');
    }
};
