<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers a ticket's PREVIOUS ticket_key values.
 *
 * A ticket's key follows the owning company of its store (see TicketObserver),
 * so changing the Company/Store renumbers it — e.g. TGI-2531 becomes NONO-246.
 * When that happens the old key is recorded here so that:
 *
 *   1. Inbound email replies whose subject still carries the old key
 *      (e.g. "Re: [TGI-2531] ...") continue to resolve to the SAME ticket
 *      instead of spawning a new one.
 *   2. The old number is never handed out again to a brand-new ticket
 *      (key generation reserves aliased numbers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_key_aliases', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_id');
            // A given key maps to exactly one ticket; live-key lookups always win,
            // this is only a fallback for retired keys.
            $table->string('ticket_key')->unique();
            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_key_aliases');
    }
};
