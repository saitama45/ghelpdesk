<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the customer's ORIGINAL email Message-ID exactly as received
     * (case preserved). The existing `message_id` column is normalized
     * (lowercased) for deduplication, which breaks RFC 5322 threading because
     * Message-IDs are case-sensitive. Outgoing reply notifications use this
     * column for In-Reply-To / References so mail clients (Gmail) thread them
     * under the original conversation.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('source_message_id')->nullable()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('source_message_id');
        });
    }
};
