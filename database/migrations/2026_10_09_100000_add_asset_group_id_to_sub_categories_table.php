<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the Asset Operational Health group from the Category to the Sub-Category.
 * A category ("INCIDENT - HARDWARE") is ticket taxonomy and too coarse to say what
 * a unit is; the sub-category ("Digital Easel") is what the reference sheet groups.
 *
 * NO ACTION, not nullOnDelete: reference_options already reaches assets, items and
 * tickets through categories, so a second cascading path through sub_categories
 * is one SQL Server refuses. ReferenceOptionController blocks deleting a group
 * that is still in use instead.
 *
 * categories.asset_group_id is left in place, unused — nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sub_categories', 'asset_group_id')) {
            Schema::table('sub_categories', function (Blueprint $table) {
                $table->foreignId('asset_group_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('reference_options')
                    ->onDelete('no action');
            });
        }

        $this->backfillFromCategories();
    }

    /**
     * Carry the groups already assigned on /categories over, so the board does not
     * go "Ungrouped" on deploy. A sub-category inherits a group only when every
     * fixed asset using it sat under categories of that one group — anything mixed
     * or unmapped is left for someone to decide on /sub-categories.
     */
    public function backfillFromCategories(): void
    {
        DB::table('assets')
            ->leftJoin('categories', 'categories.id', '=', 'assets.category_id')
            ->where('assets.type', 'Fixed')
            ->whereNotNull('assets.sub_category_id')
            ->select('assets.sub_category_id', 'categories.asset_group_id')
            ->distinct()
            ->get()
            ->groupBy('sub_category_id')
            ->each(function ($pairs, $subCategoryId) {
                $groupId = $pairs->first()->asset_group_id;

                if ($pairs->count() !== 1 || ! $groupId) {
                    return;
                }

                DB::table('sub_categories')
                    ->where('id', $subCategoryId)
                    ->whereNull('asset_group_id')
                    ->update(['asset_group_id' => $groupId]);
            });
    }

    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropForeign(['asset_group_id']);
            $table->dropColumn('asset_group_id');
        });
    }
};
