<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npc_statuses', function (Blueprint $table) {
            // Step 4 — status of DPO registration / NPC approval.
            $table->string('approval_status', 30)->default('For Submission')->after('status');
        });

        // Step 4 — payment details (only relevant once approved).
        Schema::create('npc_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('transaction_no')->nullable();
            $table->date('date_of_payment')->nullable();
            $table->string('transaction_type', 40)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamps();

            $table->unique('npc_status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_payments');

        Schema::table('npc_statuses', function (Blueprint $table) {
            $table->dropColumn('approval_status');
        });
    }
};
