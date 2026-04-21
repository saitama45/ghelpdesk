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
        Schema::create('kb_article_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_article_id')->constrained('kb_articles')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['kb_article_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kb_article_views');
    }
};
