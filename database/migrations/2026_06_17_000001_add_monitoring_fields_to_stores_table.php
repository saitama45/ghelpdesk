<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('address')->nullable()->after('name');
            $table->string('legal_company')->nullable()->after('address');
            $table->string('monitoring_status')->nullable()->default('OPEN')->after('legal_company');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['address', 'legal_company', 'monitoring_status']);
        });
    }
};
