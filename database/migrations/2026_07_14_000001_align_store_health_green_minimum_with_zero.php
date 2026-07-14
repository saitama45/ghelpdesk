<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'thresholds')
            ->where('key', 'like', 'threshold_green_min%')
            ->where('value', '1')
            ->update(['value' => '0']);
    }

    public function down(): void
    {
        // Intentionally irreversible: a value of zero may have been explicitly
        // configured after this migration and must not be changed on rollback.
    }
};
