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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('status')->default('Planning'); // Planning, In Progress, Delayed, Completed
            $table->date('turn_over_date')->nullable();
            $table->date('training_date')->nullable();
            $table->date('testing_date')->nullable();
            $table->date('mock_service_date')->nullable();
            $table->date('turn_over_to_franchisee_date')->nullable();
            $table->date('target_go_live')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
