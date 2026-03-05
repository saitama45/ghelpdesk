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
        if ($driver === 'sqlsrv') {
            DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed', 'waiting'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement("ALTER TABLE tickets DROP CONSTRAINT IF EXISTS CK_Tickets_Status");
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('open', 'in_progress', 'closed', 'waiting'))");
        }
    }
};
