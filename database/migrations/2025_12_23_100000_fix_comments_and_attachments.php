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
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->renameColumn('created_date', 'created_at');
            $table->timestamp('updated_at')->nullable(); // Add updated_at for standard timestamps
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->foreignUuid('comment_id')->nullable()->constrained('ticket_comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropForeign(['comment_id']);
            $table->dropColumn('comment_id');
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->renameColumn('created_at', 'created_date');
            $table->dropColumn('updated_at');
        });
    }
};
