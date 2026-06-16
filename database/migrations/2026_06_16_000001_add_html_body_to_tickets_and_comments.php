<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores a sanitized, rich-HTML version of email bodies that contain tables,
     * so the original tabular formatting survives instead of being flattened to
     * plain text. Plain-text columns remain the source of truth for search,
     * dedup hashing and exports; these are display-only fallbacks.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->text('description_html')->nullable()->after('description');
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->text('comment_html')->nullable()->after('comment_text');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('description_html');
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropColumn('comment_html');
        });
    }
};
