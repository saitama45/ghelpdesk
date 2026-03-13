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
        // Migrate existing data
        DB::table('tickets')->where('status', 'waiting')->update(['status' => 'waiting_service_provider']);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            if ($driver === 'sqlsrv') {
                DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            } else {
                // For MySQL, drop the constraint if it exists (requires MySQL 8.0.19+)
                // or just ignore if it's not strictly enforced in older versions
                try {
                    DB::statement("ALTER TABLE tickets DROP CHECK CK_Tickets_Status");
                } catch (\Exception $e) {
                    // Ignore if constraint doesn't exist
                }
            }
            
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migrate data back
        DB::table('tickets')->whereIn('status', ['waiting_service_provider', 'waiting_client_feedback'])->update(['status' => 'waiting']);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            if ($driver === 'sqlsrv') {
                DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            } else {
                try {
                    DB::statement("ALTER TABLE tickets DROP CHECK CK_Tickets_Status");
                } catch (\Exception $e) {
                }
            }
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting'))");
        }
    }
};
