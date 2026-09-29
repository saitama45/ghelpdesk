<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket Duty: a schedule tagged on /schedules makes its owner eligible to be
 * auto-assigned new tickets for that shift (App\Services\TicketDutyAssigner).
 * Additive only; every existing schedule starts untagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('schedules', 'ticket_duty')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->boolean('ticket_duty')->default(false);
            // Serves "who is on duty at T" and "who is on duty next".
            $table->index(['ticket_duty', 'start_time', 'end_time'], 'schedules_ticket_duty_window_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('schedules', 'ticket_duty')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_ticket_duty_window_index');
            $table->dropColumn('ticket_duty');
        });
    }
};
