<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A milestone on the Gantt is a grouping of top-level rows sharing one
     * `project_tasks.category` — it has never been a record of its own, so there
     * was nowhere to record who owns it. This table gives each milestone an
     * owner, which the plan's edit rules key off (see App\Support\ProjectPlanAccess).
     */
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Mirrors project_tasks.category. NULL categories are stored as
            // 'General', exactly how the Gantt groups and labels them.
            $table->string('category');
            // The milestone owner. 'no action' — project_id already cascades to
            // this table and SQL Server refuses a second cascade path.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['project_id', 'category']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
