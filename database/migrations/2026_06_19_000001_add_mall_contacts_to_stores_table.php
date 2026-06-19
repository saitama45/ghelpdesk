<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stores ADD mall_contacts NVARCHAR(MAX) NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('stores', 'mall_contacts')) {
            DB::statement('ALTER TABLE stores DROP COLUMN mall_contacts');
        }
    }
};
