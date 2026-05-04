<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_records', function (Blueprint $table) {
            $table->integer('current_approval_level')->default(0);
        });

        Schema::create('table_record_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_record_id')->constrained('table_records')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users');
            $table->integer('level');
            $table->text('remarks')->nullable();
            $table->text('approver_data')->nullable(); // JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_record_approvals');
        Schema::table('table_records', function (Blueprint $table) {
            $table->dropColumn('current_approval_level');
        });
    }
};
