<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 3 — DPO Registration. Free-text registration content is stored
        // as one JSON document (organization, sector, head of org, compliance
        // officer, classification, and the repeatable data processing systems).
        Schema::create('npc_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique('npc_status_id');
        });

        // Step 3 — supporting document uploads (one row per uploaded file).
        Schema::create('npc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 60);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['npc_status_id', 'doc_type']);
        });

        // Positional rename of step 3's key/label.
        DB::table('npc_status_workflow_steps')
            ->where('key', 'application_signing')
            ->update(['key' => 'dpo_registration', 'label' => 'DPO Registration']);
    }

    public function down(): void
    {
        DB::table('npc_status_workflow_steps')
            ->where('key', 'dpo_registration')
            ->update(['key' => 'application_signing', 'label' => 'Application Signing']);

        Schema::dropIfExists('npc_documents');
        Schema::dropIfExists('npc_registrations');
    }
};
