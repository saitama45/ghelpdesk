<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stock_ins', 'source_stock_in_id')) {
            Schema::table('stock_ins', function (Blueprint $table) {
                $table->foreignId('source_stock_in_id')->nullable()->after('id');
            });
        }

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->foreign('source_stock_in_id', 'stock_ins_source_stock_in_fk')
                ->references('id')
                ->on('stock_ins')
                ->onDelete('no action');
            $table->index(['asset_id', 'destination_location', 'status'], 'stock_ins_asset_destination_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropForeign('stock_ins_source_stock_in_fk');
            $table->dropIndex('stock_ins_asset_destination_status_idx');
            $table->dropColumn('source_stock_in_id');
        });
    }
};
