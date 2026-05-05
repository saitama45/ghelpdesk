<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dateTime('posted_date')->nullable()->after('posted_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn('posted_date');
        });
    }
};
