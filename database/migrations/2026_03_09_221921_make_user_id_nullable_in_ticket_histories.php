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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlsrv') {
            // SQL Server: Drop constraint first, then modify column
            // We need to find the name of the foreign key constraint
            $results = DB::select("
                SELECT name 
                FROM sys.foreign_keys 
                WHERE parent_object_id = OBJECT_ID('ticket_histories') 
                AND referenced_object_id = OBJECT_ID('users')
            ");

            if (!empty($results)) {
                $constraintName = $results[0]->name;
                DB::statement("ALTER TABLE ticket_histories DROP CONSTRAINT {$constraintName}");
            }

            DB::statement("ALTER TABLE ticket_histories ALTER COLUMN user_id BIGINT NULL");
            
            // Re-add the constraint as nullable
            DB::statement("ALTER TABLE ticket_histories ADD CONSTRAINT fk_ticket_histories_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        } else {
            Schema::table('ticket_histories', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
