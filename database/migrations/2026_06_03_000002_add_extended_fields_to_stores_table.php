<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('email');
            $table->string('contact_details')->nullable()->after('contact_person');
            $table->date('opening_date')->nullable()->after('contact_details');
            $table->string('hookup')->nullable()->after('opening_date');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'contact_details', 'opening_date', 'hookup']);
        });
    }
};
