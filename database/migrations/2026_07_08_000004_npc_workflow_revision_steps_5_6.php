<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Split the old combined "store distribution" step into Step 5 (receiving)
        // and Step 6 (downloads & confirmation). Payment moved into Step 4, so the
        // old payment step slot becomes Step 5.
        DB::table('npc_status_workflow_steps')
            ->where('key', 'payment_processing')
            ->update(['key' => 'store_receiving', 'label' => 'Store/Office Receiving']);

        DB::table('npc_status_workflow_steps')
            ->where('key', 'store_distribution')
            ->update(['key' => 'store_downloads', 'label' => 'Store/Office Downloads & Confirmation']);

        // Store/Office proof-of-use: one screenshot/photo per (status, store),
        // uploaded by the store user before an admin may confirm the seals.
        Schema::create('npc_store_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // Plain nullable actor id (no FK — SQL Server rejects the extra
            // SET NULL cascade path, mirroring npc_seal_receipts).
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['npc_status_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_store_proofs');

        DB::table('npc_status_workflow_steps')
            ->where('key', 'store_downloads')
            ->update(['key' => 'store_distribution', 'label' => 'For Store Receiving']);

        DB::table('npc_status_workflow_steps')
            ->where('key', 'store_receiving')
            ->update(['key' => 'payment_processing', 'label' => 'Payment Processing']);
    }
};
