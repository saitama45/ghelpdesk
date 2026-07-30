<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a CC address the mail server has permanently rejected.
 *
 * Bounces arrive from mailer-daemon, which the fetcher drops as a banned sender,
 * so a CC that can never receive mail used to be re-mailed on every ticket
 * update forever and nobody was told. The fetcher now parses the delivery-status
 * report and stamps the offending row here; mail paths skip it, while the ticket
 * page still shows it (flagged) so staff can fix the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ccs', function (Blueprint $table) {
            $table->timestamp('undeliverable_at')->nullable()->after('name');
            $table->string('undeliverable_reason', 500)->nullable()->after('undeliverable_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ccs', function (Blueprint $table) {
            $table->dropColumn(['undeliverable_at', 'undeliverable_reason']);
        });
    }
};
