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
        Schema::create('agent_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->uuid('ticket_id')->nullable();
            $table->enum('type', [
                'fast_resolution',
                'ontime_resolution',
                'late_resolution',
                'fcr_bonus',
                'happy_customer',
                'unhappy_customer',
                'quest_bonus',
            ]);
            $table->integer('points');
            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('set null');
            $table->index(['agent_id', 'awarded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_point_transactions');
    }
};
