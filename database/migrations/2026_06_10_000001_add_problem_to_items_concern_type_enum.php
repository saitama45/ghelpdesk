<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'concern_type')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `items` MODIFY `concern_type` ENUM('Incident', 'Service Request', 'Problem') NOT NULL DEFAULT 'Incident'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_concern_type_check');
            DB::statement("ALTER TABLE items ADD CONSTRAINT items_concern_type_check CHECK (concern_type IN ('Incident', 'Service Request', 'Problem'))");
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'concern_type')) {
            return;
        }

        DB::table('items')
            ->where('concern_type', 'Problem')
            ->update(['concern_type' => 'Incident']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `items` MODIFY `concern_type` ENUM('Incident', 'Service Request') NOT NULL DEFAULT 'Incident'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_concern_type_check');
            DB::statement("ALTER TABLE items ADD CONSTRAINT items_concern_type_check CHECK (concern_type IN ('Incident', 'Service Request'))");
        }
    }
};
