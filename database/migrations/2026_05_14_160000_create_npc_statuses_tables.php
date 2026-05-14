<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npc_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('validity_from');
            $table->date('validity_to');
            $table->string('status', 50);
            $table->string('dpo_seal_path')->nullable();
            $table->string('dpo_seal_name')->nullable();
            $table->string('dpo_seal_mime_type', 120)->nullable();
            $table->unsignedBigInteger('dpo_seal_size')->nullable();
            $table->string('dpo_registration_path')->nullable();
            $table->string('dpo_registration_name')->nullable();
            $table->string('dpo_registration_mime_type', 120)->nullable();
            $table->unsignedBigInteger('dpo_registration_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year']);
            $table->index(['year', 'status']);
        });

        Schema::create('npc_status_store', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->timestamps();

            $table->unique(['npc_status_id', 'store_id']);
            $table->unique(['year', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_status_store');
        Schema::dropIfExists('npc_statuses');
    }
};
