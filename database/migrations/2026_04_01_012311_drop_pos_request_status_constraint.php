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
        // Drop the specific SQL Server check constraint that is blocking dynamic statuses
        try {
            DB::statement('ALTER TABLE pos_requests DROP CONSTRAINT CK__pos_reque__statu__788A9DEF');
        } catch (\Exception $e) {
            // Might already be dropped or have different name in other environments
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Usually not safe to re-add with exact random name
    }
};
