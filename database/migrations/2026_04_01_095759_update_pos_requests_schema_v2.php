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
            $table->date('effectivity_date')->nullable()->change();
        });

        Schema::table('pos_request_details', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('category');
            $table->date('validity_date')->nullable()->after('sub_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_request_details', function (Blueprint $table) {
            $table->dropColumn(['sub_category', 'validity_date']);
        });

        Schema::table('pos_requests', function (Blueprint $table) {
            $table->date('effectivity_date')->nullable(false)->change();
        });
    }
};
