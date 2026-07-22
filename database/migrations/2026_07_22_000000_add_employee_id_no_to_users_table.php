<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id_no')->nullable()->after('name');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement(
                'CREATE UNIQUE INDEX [users_employee_id_no_unique] '
                .'ON [users] ([employee_id_no]) '
                .'WHERE [employee_id_no] IS NOT NULL'
            );

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id_no');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id_no']);
            $table->dropColumn('employee_id_no');
        });
    }
};
