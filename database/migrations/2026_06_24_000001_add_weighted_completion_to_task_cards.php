<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            // null/'none' = legacy binary checklist count; otherwise the level whose
            // weights drive this card's completion: 'checklist' | 'item' | 'subtask'.
            $table->string('weight_basis')->nullable()->after('due_complete');
        });

        Schema::table('task_checklists', function (Blueprint $table) {
            $table->decimal('weight', 6, 2)->default(0)->after('title');
        });

        Schema::table('task_checklist_items', function (Blueprint $table) {
            // Used for both items and subtasks (subtasks are items with a parent_item_id).
            $table->decimal('weight', 6, 2)->default(0)->after('is_complete');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropColumn('weight_basis');
        });

        Schema::table('task_checklists', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
