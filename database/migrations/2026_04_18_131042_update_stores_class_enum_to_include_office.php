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
            // Find and drop the check constraint for class column in stores table
            $results = DB::select("
                SELECT name 
                FROM sys.check_constraints 
                WHERE parent_object_id = OBJECT_ID('stores') 
                AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('stores'), 'class', 'ColumnId')
            ");

            foreach ($results as $row) {
                DB::statement("ALTER TABLE stores DROP CONSTRAINT {$row->name}");
            }
        }

        Schema::table('stores', function (Blueprint $table) {
            // Change to string to support "Office" and future classes
            $table->string('class')->default('Regular')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->enum('class', ['Regular', 'Kitchen'])->default('Regular')->change();
        });
    }
};
