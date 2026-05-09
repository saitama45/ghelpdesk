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
        Schema::table('form_records', function (Blueprint $table) {
            $table->foreignId('request_type_id')->nullable()->after('form_definition_id')->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->dropForeign(['request_type_id']);
            $table->dropColumn('request_type_id');
        });
    }
};
