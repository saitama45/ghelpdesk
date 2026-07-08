<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npc_statuses', function (Blueprint $table) {
            // New vs Renewal encoding context for the year's record.
            $table->string('entry_type', 20)->default('New')->after('year');
            // Step 1 — Account Registration. Password is encrypted at rest
            // (Laravel Crypt via the model cast) so it can be revealed to
            // permitted users; it is intentionally NOT hashed.
            $table->string('register_email')->nullable()->after('status');
            $table->text('register_password')->nullable()->after('register_email');
        });

        // Step 2 — DPO Profile Information (one row per NPC status record).
        Schema::create('npc_dpo_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('middle_initial', 20)->nullable();
            $table->string('last_name')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('designation')->nullable();
            $table->date('date_designated_dpo')->nullable();
            $table->string('official_dpo_email')->nullable();
            $table->string('mobile_no', 50)->nullable();
            $table->string('telephone_no', 50)->nullable();
            $table->string('role')->nullable()->default('PIC/PIP');
            $table->timestamps();

            $table->unique('npc_status_id');
        });

        // Step 2 — generated backup codes (kept as ordered rows per record).
        Schema::create('npc_backup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('npc_status_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('npc_status_id');
        });

        // Positional remap of the first two workflow step keys/labels for any
        // existing records. Later steps are handled in subsequent increments.
        $renames = [
            'form_completion' => ['key' => 'account_registration', 'label' => 'Account Registration'],
            'documents_uploading' => ['key' => 'dpo_profile', 'label' => 'DPO Profile Information'],
        ];

        foreach ($renames as $oldKey => $new) {
            DB::table('npc_status_workflow_steps')
                ->where('key', $oldKey)
                ->update(['key' => $new['key'], 'label' => $new['label']]);
        }
    }

    public function down(): void
    {
        $reverts = [
            'account_registration' => ['key' => 'form_completion', 'label' => 'Form Completion'],
            'dpo_profile' => ['key' => 'documents_uploading', 'label' => 'Documents Uploading'],
        ];

        foreach ($reverts as $newKey => $old) {
            DB::table('npc_status_workflow_steps')
                ->where('key', $newKey)
                ->update(['key' => $old['key'], 'label' => $old['label']]);
        }

        Schema::dropIfExists('npc_backup_codes');
        Schema::dropIfExists('npc_dpo_profiles');

        Schema::table('npc_statuses', function (Blueprint $table) {
            $table->dropColumn(['entry_type', 'register_email', 'register_password']);
        });
    }
};
