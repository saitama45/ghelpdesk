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
        Schema::create('agent_quest_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('quest_id');
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('quest_id')->references('id')->on('quests')->onDelete('cascade');
            $table->unique(['agent_id', 'quest_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_quest_progress');
    }
};
