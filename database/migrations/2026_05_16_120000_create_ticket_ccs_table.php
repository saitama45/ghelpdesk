<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_ccs', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            // No FK on user_id / created_by to avoid SQL Server multiple-cascade-path errors.
            // Soft references — clean up application-side if needed.
            $table->unique(['ticket_id', 'email']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_ccs');
    }
};
