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
        Schema::create('ticket_sla_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained()->onDelete('cascade');
            
            $table->timestamp('response_target_at')->nullable();
            $table->timestamp('resolution_target_at')->nullable();
            
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            
            $table->boolean('is_response_breached')->default(false);
            $table->boolean('is_resolution_breached')->default(false);
            
            $table->timestamp('paused_at')->nullable();
            $table->integer('total_paused_seconds')->default(0);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_metrics');
    }
};
