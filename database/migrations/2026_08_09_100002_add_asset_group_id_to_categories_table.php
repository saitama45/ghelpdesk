<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps each Category to one Asset Operational Health group. Nullable, because
 * most categories are ticket-only taxonomy and never describe an asset.
 *
 * nullOnDelete is safe here: reference_options is a leaf table with no other
 * path into categories, so SQL Server cannot see multiple cascade paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('asset_group_id')
                ->nullable()
                ->after('description')
                ->constrained('reference_options')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['asset_group_id']);
            $table->dropColumn('asset_group_id');
        });
    }
};
