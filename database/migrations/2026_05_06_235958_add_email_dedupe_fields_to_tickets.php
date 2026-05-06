<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tickets', 'email_body_hash')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('email_body_hash', 64)->nullable()->after('message_id');
                $table->index('email_body_hash', 'tickets_email_body_hash_index');
                $table->index(['sender_email', 'email_body_hash'], 'tickets_sender_body_hash_index');
            });
        }

        if (!Schema::hasColumn('ticket_comments', 'message_id')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->string('message_id')->nullable()->after('sender_name');
                $table->index('message_id', 'ticket_comments_message_id_index');
            });
        }

        if (!Schema::hasColumn('ticket_comments', 'email_body_hash')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->string('email_body_hash', 64)->nullable()->after('message_id');
                $table->index('email_body_hash', 'ticket_comments_email_body_hash_index');
                $table->index(['sender_email', 'email_body_hash'], 'ticket_comments_sender_body_hash_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_comments', 'email_body_hash')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->dropIndex('ticket_comments_email_body_hash_index');
                $table->dropIndex('ticket_comments_sender_body_hash_index');
                $table->dropColumn('email_body_hash');
            });
        }

        if (Schema::hasColumn('ticket_comments', 'message_id')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->dropIndex('ticket_comments_message_id_index');
                $table->dropColumn('message_id');
            });
        }

        if (Schema::hasColumn('tickets', 'email_body_hash')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tickets_email_body_hash_index');
                $table->dropIndex('tickets_sender_body_hash_index');
                $table->dropColumn('email_body_hash');
            });
        }
    }
};
