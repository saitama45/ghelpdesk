<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('users')->where('email', 'admin@gmail.com')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if (!$adminId) {
            return;
        }

        DB::table('users')
            ->whereNull('created_by')
            ->update(['created_by' => $adminId]);

        DB::table('users')
            ->whereNull('updated_by')
            ->update(['updated_by' => $adminId]);
    }

    public function down(): void
    {
        // Audit backfills are intentionally retained.
    }
};
