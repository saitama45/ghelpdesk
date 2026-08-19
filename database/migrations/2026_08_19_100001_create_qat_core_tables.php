<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QAT Tracker — structure tables.
 *
 * QAT is the internal quality pass that runs BEFORE the client-facing UAT: the
 * same sections -> cases -> case x participant verdict matrix, but executed by
 * staff and gated on a sign-off from the tester's immediate manager. A signed-off
 * QAT cycle can then be promoted into a UAT cycle, carrying its test cases over.
 *
 * Deliberate differences from the UAT tables:
 *  - No access_token/token_expires_at/last_accessed_at. QAT has no no-login
 *    portal — everyone running it is staff with an account — so the tokenised
 *    columns and their filtered-unique-index workaround are simply absent.
 *  - qat_cycles carries the manager approval state (snapshotted approver ids,
 *    submitted_*, promoted_*) and the loose link to a UAT cycle.
 *  - qat_cases records where a copied case came from (source_uat_case_id).
 *
 * Every foreign key is `no action`. SQL Server rejects a table reachable from one
 * parent by more than one cascade path, and qat_case_results is reachable from
 * qat_cycles via cases AND via participants. Cleanup is done explicitly in
 * QatCycle::cascadeDelete() instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qat_cycles')) {
            Schema::create('qat_cycles', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('title');
                // What is under test — "Planning Website", "ENTECH M4".
                $table->string('system_name')->nullable();
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('cycle_no')->default(1);
                $table->string('environment', 60)->default('Web');
                // [{label, url}]
                $table->text('links')->nullable();
                $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                $table->foreignId('qa_lead_id')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('dev_lead_id')->nullable()->constrained('users')->onDelete('no action');
                // draft | testing | for_approval | signed_off | returned | cancelled
                $table->string('status', 30)->default('draft');
                $table->date('start_date')->nullable();
                $table->date('target_signoff_date')->nullable();
                $table->date('go_live_date')->nullable();
                // Non-critical items are excluded from the readiness gate. There is
                // no signoff_requires_all twin of the UAT flag: exactly one manager
                // decides a QAT cycle, so "all approvers" has nothing to quantify.
                $table->boolean('gate_on_critical_only')->default(true);

                // --- manager sign-off ---
                // The eligible managers RESOLVED AT SUBMIT and frozen here. The org
                // chart can change while a cycle waits; snapshotting means a pending
                // sign-off can never be orphaned, and membership of this list is the
                // authority for who may decide (same approach as
                // schedule_change_requests.assigned_approver_ids).
                $table->text('approver_user_ids')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamp('submitted_at')->nullable();

                // --- link to UAT ---
                // uat_cycle_id is the loose association (may be set by hand);
                // promoted_uat_cycle_id records the cycle THIS one created, and is
                // what makes promotion idempotent.
                //
                // Deliberately NOT foreign keys. A real constraint here would make
                // UatCycle::cascadeDelete() throw the moment any QAT cycle pointed
                // at that UAT cycle — a working module would start failing because
                // of a row in a table it knows nothing about. The modules are
                // independent by design, so the link is a soft reference: a dangling
                // id resolves to a null relation, which the UI already handles.
                $table->unsignedBigInteger('uat_cycle_id')->nullable();
                $table->unsignedBigInteger('promoted_uat_cycle_id')->nullable();
                $table->foreignId('promoted_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamp('promoted_at')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->index('status');
                $table->index(['company_id', 'status']);
                $table->index(['department_id', 'status']);
                $table->index('uat_cycle_id');
                $table->index('promoted_uat_cycle_id');
            });
        }

        if (! Schema::hasTable('qat_sections')) {
            Schema::create('qat_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                $table->string('name');
                $table->string('description')->nullable();
                $table->boolean('is_critical')->default(true);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();

                $table->index(['qat_cycle_id', 'order']);
            });
        }

        if (! Schema::hasTable('qat_cases')) {
            Schema::create('qat_cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                $table->foreignId('qat_section_id')->nullable()->constrained('qat_sections')->onDelete('no action');
                // "UI-UX-01"; auto-generated when left blank.
                $table->string('case_key', 40);
                $table->string('screen')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                // Kept as authored text (numbered, nested a/b/c) rather than split
                // into rows — testers paste procedures straight from the source doc.
                $table->text('steps')->nullable();
                $table->text('expected_results')->nullable();
                $table->boolean('is_critical')->default(true);
                // low | medium | high | critical
                $table->string('priority', 20)->default('medium');
                $table->unsignedInteger('order')->default(0);
                // Copy provenance when this case was seeded from a UAT cycle.
                // Intentionally NOT a foreign key: the source case may be deleted
                // later, and losing the breadcrumb is preferable to blocking that.
                $table->unsignedBigInteger('source_uat_case_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->unique(['qat_cycle_id', 'case_key']);
                $table->index(['qat_cycle_id', 'qat_section_id', 'order']);
            });
        }

        if (! Schema::hasTable('qat_participants')) {
            Schema::create('qat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qat_cycle_id')->constrained('qat_cycles')->onDelete('no action');
                // department | user — a team column, or one named internal tester.
                $table->string('kind', 20)->default('department');
                // Matrix column header: "BD", "Ops Support", "Acctg".
                $table->string('label', 80);
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
                // Populated for QAT far more often than for UAT: every participant
                // here is staff, so the user row is the primary identity.
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('contact_name')->nullable();
                $table->string('contact_email')->nullable();
                // tester | reviewer | observer. A reviewer's answer outranks a
                // tester's in the same column, mirroring UAT's approver precedence.
                // The MANAGER sign-off is a separate thing entirely and is not a
                // participant role — see qat_signoffs.
                $table->string('role', 20)->default('tester');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();

                $table->index(['qat_cycle_id', 'order']);
                $table->index(['qat_cycle_id', 'role']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qat_participants');
        Schema::dropIfExists('qat_cases');
        Schema::dropIfExists('qat_sections');
        Schema::dropIfExists('qat_cycles');
    }
};
