<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `stamp_programs` with the presentational fields the mobile loyalty
 * app (bms / "Coffee Bean & Tea Leaf", a separate Flutter app) needs but the
 * staff-facing Stamps module never modeled: an emoji/icon, a filter tag, a
 * free-text description of which items qualify, the reward text, terms, and
 * an active window.
 *
 * Every column is nullable and additive — existing rows (there are already 3
 * real campaigns as of 2026-08-25) are untouched, and the Stamps/Index.vue
 * admin UI keeps working exactly as before since it never reads these.
 *
 * There is deliberately no `code` column: the mobile app derives a stable
 * sync key as `SP-{id}` at the API layer (see Api\CampaignsController) rather
 * than needing a second identifier to keep unique and backfilled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stamp_programs', function (Blueprint $table) {
            $table->string('emoji', 8)->nullable()->after('description');
            $table->string('tag', 60)->nullable()->after('emoji');
            $table->text('eligible_items_description')->nullable()->after('auto_stamp_amount');
            $table->text('reward_description')->nullable()->after('eligible_items_description');
            $table->text('terms_and_conditions')->nullable()->after('reward_description');
            $table->timestamp('starts_at')->nullable()->after('terms_and_conditions');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->integer('display_order')->default(0)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('stamp_programs', function (Blueprint $table) {
            $table->dropColumn([
                'emoji',
                'tag',
                'eligible_items_description',
                'reward_description',
                'terms_and_conditions',
                'starts_at',
                'ends_at',
                'display_order',
            ]);
        });
    }
};
