<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QAT Tracker — execution tables.
 *
 * qat_case_results is the cell of the verdict matrix: one row per test case per
 * participant, collapsed to one column per department on display.
 *
 * qat_signoffs carries the piece UAT does not have: the immediate manager's
 * decision, plus the waiver that lets a manager sign off over the top of an open
 * blocker/major finding. Recording the waiver — which findings, and why — is the
 * whole point of gating on them; a gate that can be silently stepped around is
 * not a control.
 *
 * All foreign keys are `no action` — see the core-tables migration for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qat_case_results')) {
            Schema::create('qat_case_results', function (Blueprint $table) {
                $table->id();
                // Denormalised so cycle-wide tallies never join through cases.
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                $table->foreignId('qat_case_id')->constrained('qat_cases')->onDelete('no action');
                $table->foreignId('qat_participant_id')->constrained('qat_participants')->onDelete('no action');
                // pending | passed | failed | blocked | not_applicable | ongoing
                $table->string('result', 20)->default('pending');
                $table->text('remarks')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                // Kept alongside the id so the audit trail survives a user rename.
                $table->string('executed_by_name')->nullable();
                $table->timestamps();

                $table->unique(['qat_case_id', 'qat_participant_id'], 'qat_case_results_cell_unique');
                $table->index(['qat_cycle_id', 'result']);
                $table->index(['qat_participant_id', 'result']);
            });
        }

        if (! Schema::hasTable('qat_findings')) {
            Schema::create('qat_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                $table->foreignId('qat_case_id')->nullable()->constrained('qat_cases')->onDelete('no action');
                $table->foreignId('qat_participant_id')->nullable()->constrained('qat_participants')->onDelete('no action');
                // "F-001", unique inside a cycle.
                $table->string('reference', 30);
                $table->string('title');
                $table->text('details')->nullable();
                // cosmetic | minor | major | blocker.
                // An unresolved major or blocker gates the manager sign-off.
                $table->string('severity', 20)->default('minor');
                // open | in_progress | for_retest | closed | deferred
                $table->string('status', 20)->default('open');
                $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                // Link to a real helpdesk ticket once the fix is scheduled.
                // Tickets are keyed by UUID, not by an auto-increment.
                $table->uuid('ticket_id')->nullable();
                $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('no action');
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();

                // The manager's override, stamped on the finding itself rather than
                // only on the sign-off row. It makes the gate a plain
                // `whereNull('waived_at')` instead of a diff against a json column,
                // and it means the defect register itself shows which items were
                // waived, by whom and why — the ledger alone would hide that.
                $table->timestamp('waived_at')->nullable();
                $table->foreignId('waived_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->text('waiver_reason')->nullable();

                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('reported_by_name')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->unique(['qat_cycle_id', 'reference']);
                $table->index(['qat_cycle_id', 'status']);
                $table->index(['qat_cycle_id', 'severity']);
                // The gate query filters on all three plus waived_at IS NULL.
                $table->index(['qat_cycle_id', 'status', 'severity']);
                $table->index('ticket_id');
            });
        }

        if (! Schema::hasTable('qat_evidence')) {
            Schema::create('qat_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                // Attached to the verdict that produced it, or to a finding.
                $table->foreignId('qat_case_result_id')->nullable()->constrained('qat_case_results')->onDelete('no action');
                $table->foreignId('qat_finding_id')->nullable()->constrained('qat_findings')->onDelete('no action');
                $table->string('label', 40)->nullable();
                $table->string('file_name');
                $table->string('file_path');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('uploaded_by_name')->nullable();
                $table->timestamps();

                $table->index('qat_case_result_id');
                $table->index('qat_finding_id');
            });
        }

        if (! Schema::hasTable('qat_signoffs')) {
            Schema::create('qat_signoffs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                // Set for a per-department review acknowledgement; NULL for the
                // cycle-level manager decision, which is the one that gates
                // promotion to UAT.
                $table->foreignId('qat_participant_id')->nullable()->constrained('qat_participants')->onDelete('no action');
                // review | manager
                $table->string('stage', 20)->default('manager');
                // passed | passed_with_reservation | not_accepted
                $table->string('result', 40)->default('passed');
                $table->text('remarks')->nullable();

                // The blocker override. A manager may accept a cycle that still has
                // unresolved major/blocker findings ONLY by naming them and saying
                // why — both are recorded here and shown in the ledger for good.
                $table->text('waived_finding_ids')->nullable();
                $table->text('waiver_reason')->nullable();
                // Who was eligible to decide at the moment of the decision, copied
                // off the cycle so the ledger stays readable after a re-submission
                // resolves a different set of managers.
                $table->text('resolved_approver_ids')->nullable();

                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('confirmed_name')->nullable();
                $table->string('confirmed_email')->nullable();
                // Path to the hand-drawn signature PNG on the public disk. The
                // image is stored as a file, not as a base64 data URL in the row:
                // a signature in an nvarchar(MAX) column would be dragged across
                // the link by every query that touched the ledger.
                $table->string('signature_path')->nullable();
                $table->string('ip_address', 45)->nullable();
                // Superseded rows are kept: re-signing appends, never overwrites.
                $table->boolean('is_current')->default(true);
                $table->timestamps();

                $table->index(['qat_cycle_id', 'stage', 'is_current']);
                $table->index(['qat_participant_id', 'is_current']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qat_signoffs');
        Schema::dropIfExists('qat_evidence');
        Schema::dropIfExists('qat_findings');
        Schema::dropIfExists('qat_case_results');
    }
};
