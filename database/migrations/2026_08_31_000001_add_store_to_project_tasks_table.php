<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQL Server executes the column, foreign key and index as separate
        // statements. If one fails, the earlier statements are not rolled back,
        // so each step must be restart-safe.
        if (! Schema::hasColumn('project_tasks', 'store_id')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable()->after('project_id');
            });
        }

        $hasForeignKey = collect(Schema::getForeignKeys('project_tasks'))
            ->contains(fn (array $key) => $key['name'] === 'project_tasks_store_id_foreign'
                || $key['columns'] === ['store_id']);

        if (! $hasForeignKey) {
            Schema::table('project_tasks', function (Blueprint $table) {
                // NO ACTION is intentional. SET NULL creates a second cascade
                // path from stores -> projects/project_tasks on SQL Server.
                // Historical task rows keep their store reference, so a store
                // in an active rollout cannot be deleted accidentally.
                $table->foreign('store_id', 'project_tasks_store_id_foreign')
                    ->references('id')
                    ->on('stores')
                    ->noActionOnDelete()
                    ->noActionOnUpdate();
            });
        }

        if (! Schema::hasIndex('project_tasks', 'project_tasks_store_rollout_index')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->index(
                    ['project_id', 'store_id', 'activity_mode'],
                    'project_tasks_store_rollout_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('project_tasks', 'project_tasks_store_rollout_index')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->dropIndex('project_tasks_store_rollout_index');
            });
        }

        if (Schema::hasColumn('project_tasks', 'store_id')) {
            $hasForeignKey = collect(Schema::getForeignKeys('project_tasks'))
                ->contains(fn (array $key) => $key['name'] === 'project_tasks_store_id_foreign'
                    || $key['columns'] === ['store_id']);

            Schema::table('project_tasks', function (Blueprint $table) use ($hasForeignKey) {
                if ($hasForeignKey) {
                    $table->dropForeign('project_tasks_store_id_foreign');
                }
                $table->dropColumn('store_id');
            });
        }
    }
};
