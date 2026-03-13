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
        $driver = DB::connection()->getDriverName();

        // 1. Drop existing constraint first so we can update the data
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            if ($driver === 'sqlsrv') {
                DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            } else {
                try {
                    DB::statement("ALTER TABLE tickets DROP CHECK CK_Tickets_Status");
                } catch (\Exception $e) {
                    // Ignore if constraint doesn't exist
                }
            }
        }

        // 2. Migrate existing data
        DB::table('tickets')->where('status', 'waiting')->update(['status' => 'waiting_service_provider']);

        // 3. Add the new constraint
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Drop constraint
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            if ($driver === 'sqlsrv') {
                DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            } else {
                try {
                    DB::statement("ALTER TABLE tickets DROP CHECK CK_Tickets_Status");
                } catch (\Exception $e) {
                }
            }
        }

        // 2. Migrate data back
        DB::table('tickets')->whereIn('status', ['waiting_service_provider', 'waiting_client_feedback'])->update(['status' => 'waiting']);

        // 3. Restore old constraint
        if ($driver === 'sqlsrv' || $driver === 'mysql') {
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting'))");
        }
    }
};
