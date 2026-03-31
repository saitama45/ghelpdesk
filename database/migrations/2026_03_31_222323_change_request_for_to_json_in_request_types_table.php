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
        // Dynamically find and drop the CHECK constraint for 'request_for' column in SQL Server
        if (DB::getDriverName() === 'sqlsrv') {
            $constraint = DB::selectOne("
                SELECT obj.name 
                FROM sys.objects obj
                INNER JOIN sys.check_constraints chk ON obj.object_id = chk.object_id
                INNER JOIN sys.columns col ON chk.parent_object_id = col.object_id AND chk.parent_column_id = col.column_id
                WHERE obj.type = 'C' 
                AND OBJECT_NAME(chk.parent_object_id) = 'request_types'
                AND col.name = 'request_for'
            ");

            if ($constraint) {
                DB::statement("ALTER TABLE request_types DROP CONSTRAINT {$constraint->name}");
            }
        }

        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('request_for');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->json('request_for')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('request_for');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->enum('request_for', ['SAP', 'POS'])->nullable();
        });
    }
};
