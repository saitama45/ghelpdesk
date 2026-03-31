<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Dynamically find and drop the CHECK constraint for 'status' column in SQL Server
        if (DB::getDriverName() === 'sqlsrv') {
            $constraint = DB::selectOne("
                SELECT obj.name 
                FROM sys.objects obj
                INNER JOIN sys.check_constraints chk ON obj.object_id = chk.object_id
                INNER JOIN sys.columns col ON chk.parent_object_id = col.object_id AND chk.parent_column_id = col.column_id
                WHERE obj.type = 'C' 
                AND OBJECT_NAME(chk.parent_object_id) = 'pos_requests'
                AND col.name = 'status'
            ");

            if ($constraint) {
                DB::statement("ALTER TABLE pos_requests DROP CONSTRAINT {$constraint->name}");
            }
        }

        Schema::table('pos_requests', function (Blueprint $table) {
            $table->string('status')->default('Open')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_requests', function (Blueprint $table) {
            $table->enum('status', ['Open', 'Approved', 'Cancelled', 'In Progress', 'Resolved'])->default('Open')->change();
        });
    }
};
