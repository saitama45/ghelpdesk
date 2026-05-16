<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('attendance_logs', 'client_request_id')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->string('client_request_id', 36)->nullable()->after('user_id');
            });
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement(
                'CREATE UNIQUE INDEX attendance_logs_user_client_request_unique
                ON attendance_logs (user_id, client_request_id)
                WHERE client_request_id IS NOT NULL'
            );

            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unique(['user_id', 'client_request_id'], 'attendance_logs_user_client_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_logs_user_client_request_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
