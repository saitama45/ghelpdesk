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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('status'); // On-site, Off-site, WFH, SL, VL, Restday, Offset, Holiday
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->time('pickup_start')->nullable();
            $table->time('pickup_end')->nullable();
            $table->time('backlogs_start')->nullable();
            $table->time('backlogs_end')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
