<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read state for the bell's ambient reminders (Assigned Tickets, SLA Breached,
 * Missing Time-In…), which are recomputed on every poll and so have no row of
 * their own in `notifications`. One row per user per reminder type holds the
 * items (ticket keys, user|date keys) the user has acknowledged; the reminder
 * reads as unread again once an item outside that set appears.
 *
 * Kept off `users` on purpose: an nvarchar(max) there would ride along on
 * every `SELECT *` of the authenticated user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_reminder_reads')) {
            return;
        }

        Schema::create('notification_reminder_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->json('items');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reminder_reads');
    }
};
