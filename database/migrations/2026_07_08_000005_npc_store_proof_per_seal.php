<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proof of use is now per seal (DPO Seal / DPO Registration / CCTV Seal)
        // instead of one per store. Existing rows default to 'dpo_seal'.
        Schema::table('npc_store_proofs', function (Blueprint $table) {
            $table->string('seal_type', 30)->default('dpo_seal')->after('store_id');
            $table->dropUnique(['npc_status_id', 'store_id']);
            $table->unique(['npc_status_id', 'store_id', 'seal_type']);
        });
    }

    public function down(): void
    {
        Schema::table('npc_store_proofs', function (Blueprint $table) {
            $table->dropUnique(['npc_status_id', 'store_id', 'seal_type']);
            $table->unique(['npc_status_id', 'store_id']);
            $table->dropColumn('seal_type');
        });
    }
};
