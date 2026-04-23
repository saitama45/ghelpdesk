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
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->string('dr_no')->nullable()->after('receive_date');
            $table->date('dr_date')->nullable()->after('dr_no');
            $table->string('vendor')->nullable()->after('dr_date');
            $table->string('origin_location')->nullable()->after('vendor');
            $table->string('destination_location')->nullable()->after('price');
            $table->string('received_by')->nullable()->after('destination_location');
            $table->string('posted_by')->nullable()->after('received_by');
            $table->string('status')->default('For Posting')->after('posted_by');
        });

        DB::table('stock_ins')
            ->whereNotNull('location')
            ->update([
                'destination_location' => DB::raw('location'),
            ]);

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->string('location')->nullable()->after('price');
        });

        DB::table('stock_ins')
            ->whereNotNull('destination_location')
            ->update([
                'location' => DB::raw('destination_location'),
            ]);

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn([
                'dr_no',
                'dr_date',
                'vendor',
                'origin_location',
                'destination_location',
                'received_by',
                'posted_by',
                'status',
            ]);
        });
    }
};
