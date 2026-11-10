<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DTR clock-ins that break the schedule (rest day, or outside the assigned store's
 * geofence) are recorded instead of rejected, then sent to the leader as a schedule
 * change request. These columns keep that history:
 *
 * - attendance_logs.adjustment_reason  'rest_day' | 'location_mismatch' (NULL = normal log)
 * - attendance_logs.detected_store_id  store whose geofence contained the clock-in, if any
 * - schedule_change_requests.attendance_log_id  the clock-in a request was raised from
 *
 * Plain indexed columns, no foreign keys: attendance logs are never cascaded away with
 * stores, and the request link is informational (the request applies to the schedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_logs', 'adjustment_reason')) {
                $table->string('adjustment_reason', 30)->nullable();
            }

            if (! Schema::hasColumn('attendance_logs', 'detected_store_id')) {
                $table->unsignedBigInteger('detected_store_id')->nullable()->index();
            }
        });

        Schema::table('schedule_change_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule_change_requests', 'attendance_log_id')) {
                $table->uuid('attendance_log_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedule_change_requests', function (Blueprint $table) {
            $table->dropIndex(['attendance_log_id']);
            $table->dropColumn('attendance_log_id');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['detected_store_id']);
            $table->dropColumn(['adjustment_reason', 'detected_store_id']);
        });
    }
};
