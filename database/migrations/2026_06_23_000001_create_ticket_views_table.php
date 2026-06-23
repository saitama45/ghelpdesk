<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('tickets')->onDelete('cascade');
            // No cascade on user_id: tickets already cascade to this table, and a
            // second cascade path to users trips SQL Server's "multiple cascade paths".
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            // One row per user per ticket — keeps the viewer list unique.
            $table->unique(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_views');
    }
};
