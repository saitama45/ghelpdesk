<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of every inbound message the mail fetcher has made a decision about.
 *
 * Before this table the fetcher's ONLY memory of "already handled" was the
 * mailbox's \Seen flag — state that humans reading the shared inbox also mutate.
 * Any message a person opened before the fetcher's next pass became invisible
 * forever (the query is `unseen()`), and every skip path (banned sender, not
 * addressed to us, reply to a closed ticket) flagged the message Seen without
 * leaving a trace anywhere, so "why was this email never logged?" was
 * unanswerable.
 *
 * With this ledger the fetcher can safely scan recent mail regardless of read
 * state: a message is reprocessed only if no row here claims it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_intake_logs', function (Blueprint $table) {
            $table->id();

            // Normalized Message-ID, or "uid:<n>" for the rare message without one.
            $table->string('message_key', 255);
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('folder', 100)->nullable();

            $table->string('subject', 500)->nullable();
            $table->string('sender_email', 255)->nullable();
            $table->text('recipients')->nullable();

            // created | comment | duplicate | banned_sender | not_addressed_to_us
            // | department_directory | closed_ticket | no_support_email | error
            $table->string('outcome', 40);
            $table->string('error', 1000)->nullable();

            // No FK on purpose: this is an audit trail that must outlive the ticket,
            // and tickets.id is a uniqueidentifier already reached by several
            // cascade paths on SQL Server.
            $table->uuid('ticket_id')->nullable();

            // True when the message was picked up by the catch-up scan rather than
            // as unread mail — i.e. it had already been read by a human. Courtesy
            // auto-replies are suppressed for these.
            $table->boolean('is_recovered')->default(false);

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('message_key', 'email_intake_logs_message_key_unique');
            $table->index('outcome', 'email_intake_logs_outcome_index');
            $table->index('processed_at', 'email_intake_logs_processed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_intake_logs');
    }
};
