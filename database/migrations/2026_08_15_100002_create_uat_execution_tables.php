<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UAT Tracker — execution tables.
 *
 * uat_case_results is the cell of the verdict matrix: one row per test case per
 * participant. The QA workbook only had a single "Actual Results" column because
 * one tester owned the sheet; the walkthrough checklist carried a column per
 * department. Storing the verdict per participant supports both, and the single
 * workbook column is recreated on export as a roll-up.
 *
 * All foreign keys are `no action` — see the core-tables migration for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('uat_case_results')) {
            Schema::create('uat_case_results', function (Blueprint $table) {
                $table->id();
                // Denormalised so cycle-wide tallies never join through cases.
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                $table->foreignId('uat_case_id')->constrained('uat_cases')->onDelete('no action');
                $table->foreignId('uat_participant_id')->constrained('uat_participants')->onDelete('no action');
                // pending | passed | failed | blocked | not_applicable | ongoing
                $table->string('result', 20)->default('pending');
                $table->text('remarks')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                // External stakeholders have no user row — keep who typed it.
                $table->string('executed_by_name')->nullable();
                // internal | public
                $table->string('source', 20)->default('internal');
                $table->timestamps();

                $table->unique(['uat_case_id', 'uat_participant_id'], 'uat_case_results_cell_unique');
                $table->index(['uat_cycle_id', 'result']);
                $table->index(['uat_participant_id', 'result']);
            });
        }

        if (!Schema::hasTable('uat_findings')) {
            Schema::create('uat_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                $table->foreignId('uat_case_id')->nullable()->constrained('uat_cases')->onDelete('no action');
                $table->foreignId('uat_participant_id')->nullable()->constrained('uat_participants')->onDelete('no action');
                // "F-001", unique inside a cycle.
                $table->string('reference', 30);
                $table->string('title');
                $table->text('details')->nullable();
                // cosmetic | minor | major | blocker
                $table->string('severity', 20)->default('minor');
                // open | in_progress | for_retest | closed | deferred
                $table->string('status', 20)->default('open');
                $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                // Two-way link to a real helpdesk ticket once the fix is
                // scheduled. Tickets are keyed by UUID, not by an auto-increment.
                $table->uuid('ticket_id')->nullable();
                $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('no action');
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('reported_by_name')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->unique(['uat_cycle_id', 'reference']);
                $table->index(['uat_cycle_id', 'status']);
                $table->index(['uat_cycle_id', 'severity']);
                $table->index('ticket_id');
            });
        }

        if (!Schema::hasTable('uat_evidence')) {
            Schema::create('uat_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                // Attached to the verdict that produced it, or to a finding —
                // replaces the workbook's detached SS1..SSn screenshot sheet.
                $table->foreignId('uat_case_result_id')->nullable()->constrained('uat_case_results')->onDelete('no action');
                $table->foreignId('uat_finding_id')->nullable()->constrained('uat_findings')->onDelete('no action');
                $table->string('label', 40)->nullable();
                $table->string('file_name');
                $table->string('file_path');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('uploaded_by_name')->nullable();
                $table->timestamps();

                $table->index('uat_case_result_id');
                $table->index('uat_finding_id');
            });
        }

        if (!Schema::hasTable('uat_signoffs')) {
            Schema::create('uat_signoffs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                // Null participant = the cycle-level final management sign-off.
                $table->foreignId('uat_participant_id')->nullable()->constrained('uat_participants')->onDelete('no action');
                // acceptance | final
                $table->string('stage', 20)->default('acceptance');
                // passed | passed_with_reservation | not_accepted
                // "with reservation" is carried over verbatim from the source pack.
                $table->string('result', 40)->default('passed');
                $table->text('remarks')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('confirmed_name')->nullable();
                $table->string('confirmed_email')->nullable();
                $table->string('ip_address', 45)->nullable();
                // Superseded rows are kept: re-signing appends, never overwrites.
                $table->boolean('is_current')->default(true);
                $table->timestamps();

                $table->index(['uat_cycle_id', 'stage', 'is_current']);
                $table->index(['uat_participant_id', 'is_current']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_signoffs');
        Schema::dropIfExists('uat_evidence');
        Schema::dropIfExists('uat_findings');
        Schema::dropIfExists('uat_case_results');
    }
};
