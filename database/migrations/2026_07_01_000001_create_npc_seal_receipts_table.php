<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npc_seal_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('seal_type', 40); // dpo_seal | dpo_registration | cctv_seal
            $table->timestamp('downloaded_at')->nullable();
            // No FK on the actor columns: SQL Server rejects the extra
            // SET NULL cascade paths alongside the npc_status/store cascades.
            $table->unsignedBigInteger('downloaded_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamps();

            $table->unique(['npc_status_id', 'store_id', 'seal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_seal_receipts');
    }
};
