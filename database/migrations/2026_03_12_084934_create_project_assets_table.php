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
        Schema::create('project_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_task_id')->nullable()->constrained()->onDelete('no action'); // Task installing it
            $table->string('category'); // CCTV, TV, POS, IT Rack, etc
            $table->string('item_name');
            $table->string('model_specs')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('delivery_status')->nullable(); // Yes-Purchased, Pending, etc
            $table->string('responsible')->nullable(); // Franchisee, TAS, etc
            $table->date('store_delivery_date')->nullable();
            $table->date('store_setup_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_assets');
    }
};
