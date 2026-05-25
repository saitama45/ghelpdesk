<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_templates', 'milestone_order')) {
                $table->integer('milestone_order')->nullable()->after('milestone');
            }
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('project_tasks', 'milestone_order')) {
                $table->integer('milestone_order')->nullable()->after('category');
            }
        });

        $this->backfillActivityTemplateMilestoneOrder();
        $this->backfillProjectTaskMilestoneOrder();
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('project_tasks', 'milestone_order')) {
                $table->dropColumn('milestone_order');
            }
        });

        Schema::table('activity_templates', function (Blueprint $table) {
            if (Schema::hasColumn('activity_templates', 'milestone_order')) {
                $table->dropColumn('milestone_order');
            }
        });
    }

    private function backfillActivityTemplateMilestoneOrder(): void
    {
        DB::table('activity_templates')
            ->whereNull('parent_activity_template_id')
            ->select('project_template_id')
            ->distinct()
            ->orderBy('project_template_id')
            ->get()
            ->each(function ($row) {
                DB::table('activity_templates')
                    ->where('project_template_id', $row->project_template_id)
                    ->whereNull('parent_activity_template_id')
                    ->orderBy('order')
                    ->orderBy('id')
                    ->get(['id', 'milestone'])
                    ->groupBy(fn ($activity) => $activity->milestone ?: 'General')
                    ->values()
                    ->each(function ($activities, int $index) use ($row) {
                        $milestone = $activities->first()->milestone ?: 'General';
                        $query = DB::table('activity_templates')
                            ->where('project_template_id', $row->project_template_id)
                            ->where(function ($query) use ($milestone) {
                                if ($milestone === 'General') {
                                    $query->whereNull('milestone')->orWhere('milestone', 'General');
                                } else {
                                    $query->where('milestone', $milestone);
                                }
                            });

                        $query->update(['milestone_order' => $index + 1]);
                    });
            });
    }

    private function backfillProjectTaskMilestoneOrder(): void
    {
        DB::table('project_tasks')
            ->whereNull('parent_task_id')
            ->select('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->get()
            ->each(function ($row) {
                DB::table('project_tasks')
                    ->where('project_id', $row->project_id)
                    ->whereNull('parent_task_id')
                    ->whereNull('deleted_at')
                    ->orderBy('order')
                    ->orderBy('id')
                    ->get(['id', 'category'])
                    ->groupBy(fn ($task) => $task->category ?: 'General')
                    ->values()
                    ->each(function ($tasks, int $index) use ($row) {
                        $category = $tasks->first()->category ?: 'General';
                        $query = DB::table('project_tasks')
                            ->where('project_id', $row->project_id)
                            ->where(function ($query) use ($category) {
                                if ($category === 'General') {
                                    $query->whereNull('category')->orWhere('category', 'General');
                                } else {
                                    $query->where('category', $category);
                                }
                            });

                        $query->update(['milestone_order' => $index + 1]);
                    });
            });
    }
};
