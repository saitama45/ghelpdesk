<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('database.default') === 'sqlsrv') {
            // Drop existing constraint if it exists
            DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            
            // Add updated constraint including 'for_schedule'
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'for_schedule', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'sqlsrv') {
            DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            
            // Revert to previous list
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'))");
        }
    }
};
