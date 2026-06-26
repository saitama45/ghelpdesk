<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Queue Management support fields.
     *
     * - channel:           how the ticket entered the queue (walk_in / web / email / phone).
     * - queue_track_token: stable random token for the public "Track my ticket" page
     *                      (printed on the kiosk slip and emailed on creation). Indexed,
     *                      NOT a unique constraint — SQL Server treats NULLs as equal so a
     *                      unique index would only allow one untokenised row. Uniqueness is
     *                      guaranteed by the 40-char random generator instead.
     * - called_at:         when an agent called the ticket to be served ("Now serving since…").
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'channel')) {
                $table->string('channel', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('tickets', 'queue_track_token')) {
                $table->string('queue_track_token', 64)->nullable()->after('survey_token');
            }
            if (!Schema::hasColumn('tickets', 'called_at')) {
                $table->timestampTz('called_at')->nullable()->after('queue_track_token');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'queue_track_token') && !Schema::hasIndex('tickets', 'IX_Tickets_QueueTrackToken')) {
                $table->index('queue_track_token', 'IX_Tickets_QueueTrackToken');
            }
            if (Schema::hasColumn('tickets', 'channel') && !Schema::hasIndex('tickets', 'IX_Tickets_Channel')) {
                $table->index('channel', 'IX_Tickets_Channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasIndex('tickets', 'IX_Tickets_QueueTrackToken')) {
                $table->dropIndex('IX_Tickets_QueueTrackToken');
            }
            if (Schema::hasIndex('tickets', 'IX_Tickets_Channel')) {
                $table->dropIndex('IX_Tickets_Channel');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['channel', 'queue_track_token', 'called_at'],
                fn ($column) => Schema::hasColumn('tickets', $column)
            ));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
