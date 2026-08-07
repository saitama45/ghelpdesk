<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A manually-set state for a project task, e.g. "Blocked" or "For Approval".
 *
 * project_tasks.status is COMPUTED from progress (>=100 Done, >0 Ongoing, else
 * Pending) and is overwritten every time the percentage changes, so it cannot
 * hold a state a person chose. This column sits beside it: when set, it is what
 * the workspace/monitoring screens display; when NULL, the derived status shows
 * as before. Nothing overwrites it automatically.
 *
 * The allowed values live in reference_options (type = project_task_status) so
 * the list is editable without a deploy, same as project types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tasks', 'manual_status')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->string('manual_status', 100)->nullable()->after('status');
            });
        }

        $this->seedStatusOptions();
    }

    /**
     * Seed the two states the NSO workspace design calls for. Idempotent — the
     * migration re-runs safely on every environment.
     */
    private function seedStatusOptions(): void
    {
        if (! Schema::hasTable('reference_options')) {
            return;
        }

        $columns = Schema::getColumnListing('reference_options');
        $order = 1;

        foreach (['Blocked', 'For Approval'] as $value) {
            $exists = DB::table('reference_options')
                ->where('type', 'project_task_status')
                ->where('value', $value)
                ->exists();

            if ($exists) {
                $order++;
                continue;
            }

            $row = ['type' => 'project_task_status', 'value' => $value];

            // The table has grown columns over time; only fill what is there.
            if (in_array('label', $columns, true))      $row['label'] = $value;
            if (in_array('sort_order', $columns, true)) $row['sort_order'] = $order;
            if (in_array('is_active', $columns, true))  $row['is_active'] = 1;
            if (in_array('created_at', $columns, true)) $row['created_at'] = now();
            if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

            DB::table('reference_options')->insert($row);
            $order++;
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_tasks', 'manual_status')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->dropColumn('manual_status');
            });
        }

        if (Schema::hasTable('reference_options')) {
            DB::table('reference_options')->where('type', 'project_task_status')->delete();
        }
    }
};
