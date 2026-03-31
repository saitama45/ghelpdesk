<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_requests', function (Blueprint $table) {
            $table->uuid('ticket_id')->nullable()->after('request_type_id');
            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_requests', function (Blueprint $table) {
            $table->dropForeign(['pos_requests_ticket_id_foreign']);
            $table->dropColumn('ticket_id');
        });
    }
};
