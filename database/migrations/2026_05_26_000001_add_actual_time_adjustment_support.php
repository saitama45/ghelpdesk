<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_change_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('schedule_change_requests', 'request_type')) {
                $table->string('request_type')->default('schedule_change')->after('requester_id');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('log_time');
            }

            if (!Schema::hasColumn('attendance_logs', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->noActionOnDelete();
            }

            if (!Schema::hasColumn('attendance_logs', 'void_reason')) {
                $table->string('void_reason')->nullable()->after('voided_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'voided_by')) {
                $table->dropForeign(['voided_by']);
            }

            foreach (['void_reason', 'voided_by', 'voided_at'] as $column) {
                if (Schema::hasColumn('attendance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('schedule_change_requests', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_change_requests', 'request_type')) {
                $table->dropColumn('request_type');
            }
        });
    }
};
