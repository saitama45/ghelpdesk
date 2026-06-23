<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stores', 'mall_contacts')) {
            return;
        }

        // Schema builder maps `text` per driver (NVARCHAR(MAX) on SQL Server,
        // TEXT on MySQL/SQLite) so this stays cross-database compatible.
        Schema::table('stores', function (Blueprint $table) {
            $table->text('mall_contacts')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('stores', 'mall_contacts')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropColumn('mall_contacts');
            });
        }
    }
};
