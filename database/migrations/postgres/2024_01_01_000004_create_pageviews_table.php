<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('pageviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_id')->index();
            $table->uuid('visitor_id')->index();
            $table->string('site')->index();
            $table->text('url');
            $table->string('path');
            $table->text('referrer')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('session_id')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->cascadeOnDelete();
            $table->index(['site', 'path', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('pageviews');
    }
};
