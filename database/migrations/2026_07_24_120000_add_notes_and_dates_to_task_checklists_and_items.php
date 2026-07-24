<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each milestone (task_checklists), activity and subtask (task_checklist_items)
 * a free-text notes field plus a Due date, so teams can jot context and a target
 * date at every level of the board card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_checklists', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('title');
            $table->date('due_date')->nullable()->after('notes');
        });

        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('title');
            $table->date('due_date')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('task_checklists', function (Blueprint $table) {
            $table->dropColumn(['notes', 'due_date']);
        });

        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->dropColumn(['notes', 'due_date']);
        });
    }
};
