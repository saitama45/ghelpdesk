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
        if (DB::getDriverName() === 'sqlsrv') {
            // Find and drop the check constraint for store_class column
            $results = DB::select("
                SELECT name 
                FROM sys.check_constraints 
                WHERE parent_object_id = OBJECT_ID('activity_templates') 
                AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('activity_templates'), 'store_class', 'ColumnId')
            ");

            foreach ($results as $row) {
                DB::statement("ALTER TABLE activity_templates DROP CONSTRAINT {$row->name}");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No easy way to restore the specific dynamic check constraint
    }
};
