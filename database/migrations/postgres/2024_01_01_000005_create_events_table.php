<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('events', function (Blueprint $table): void {
            $table->id();
            // Nullable: a server-side goal (e.g. a purchase recorded from a
            // domain event listener, long after any HTTP hit) may only have
            // a visitor_id to attach to, not a live session.
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('visitor_id')->index();
            $table->string('site')->index();
            $table->string('name')->index();
            $table->jsonb('properties')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('dedup_key')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('session_id')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->cascadeOnDelete();
            $table->index(['site', 'name', 'created_at']);
            $table->unique(['site', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('events');
    }
};
