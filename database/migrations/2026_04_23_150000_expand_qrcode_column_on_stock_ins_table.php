<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE stock_ins ALTER COLUMN qrcode NVARCHAR(MAX) NULL');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_ins MODIFY qrcode TEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_ins ALTER COLUMN qrcode TYPE TEXT');
            return;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE stock_ins ALTER COLUMN qrcode NVARCHAR(255) NULL');
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE stock_ins MODIFY qrcode VARCHAR(255) NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_ins ALTER COLUMN qrcode TYPE VARCHAR(255)');
            return;
        }
    }
};
