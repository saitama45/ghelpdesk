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
        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('criteria_type', [
                'tickets_with_awesome_rating',
                'tickets_resolved_fast',
                'tickets_fcr',
                'tickets_resolved',
            ]);
            $table->unsignedInteger('criteria_value');
            $table->string('badge_name')->nullable();
            $table->integer('bonus_points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quests');
    }
};
