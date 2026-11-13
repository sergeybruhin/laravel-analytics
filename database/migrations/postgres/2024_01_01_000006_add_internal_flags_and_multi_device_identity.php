<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes, both about telling real traffic apart from the host's own:
 *
 * - `is_internal` on visitors and sessions. A visitor the host marks internal
 *   (its staff, a test device — see Analytics::resolveInternalUsing) keeps the
 *   flag for good, and every session it opens inherits it at write time, so
 *   reports filter on one boolean column of the table they already read
 *   instead of joining back to visitors. Stored, not dropped: the host can
 *   still look at it, and a flag set by mistake can be cleared.
 *
 * - `external_id` stops being unique per site. One customer browses from a
 *   phone and a laptop; identify() has to be able to link both visitors to the
 *   same external id, which the unique index refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->table('visitors', function (Blueprint $table): void {
            $table->boolean('is_internal')->default(false);
            $table->dropUnique(['site', 'external_id']);
            $table->index(['site', 'external_id']);
        });

        Schema::connection('analytics')->table('sessions', function (Blueprint $table): void {
            $table->boolean('is_internal')->default(false);
            $table->index(['site', 'is_internal', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['site', 'is_internal', 'started_at']);
            $table->dropColumn('is_internal');
        });

        // Uniqueness can only come back if nothing relied on its absence: once
        // two devices share an external id, rolling back keeps the plain index.
        $shared = DB::connection('analytics')->table('visitors')
            ->whereNotNull('external_id')
            ->groupBy('site', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        Schema::connection('analytics')->table('visitors', function (Blueprint $table) use ($shared): void {
            if (! $shared) {
                $table->dropIndex(['site', 'external_id']);
                $table->unique(['site', 'external_id']);
            }

            $table->dropColumn('is_internal');
        });
    }
};
