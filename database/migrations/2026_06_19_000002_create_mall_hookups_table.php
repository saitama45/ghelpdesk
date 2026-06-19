<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mall_hookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('developer')->nullable();      // mall operator (Ayala, Robinsons, SM…)
            $table->string('area')->nullable();
            $table->date('deployment_date')->nullable();
            $table->string('deployment_status')->nullable(); // Done, etc.
            $table->string('hookup_status')->nullable();     // Sending / For Accreditation / No Hook-up Requirement / N/A
            $table->string('shouldered_facility')->nullable();
            $table->boolean('with_ups')->nullable();
            $table->decimal('cost_2024', 15, 2)->default(0);
            $table->decimal('cost_2025', 15, 2)->default(0);
            $table->decimal('cost_2026', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            // Plain audit columns — avoid extra FK cascade paths on SQL Server.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('store_id');
            $table->index('hookup_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_hookups');
    }
};
