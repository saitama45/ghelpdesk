<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UAT Tracker — structure tables.
 *
 * Merges the two source artefacts the module replaces:
 *  - the QA test-script workbook (cycle header + numbered test cases with
 *    steps/expected results), and
 *  - the User Walkthrough Checklist (functional sections, and a roster of
 *    departments/stakeholders that forms the columns of the verdict matrix).
 *
 * Every foreign key is `no action`. SQL Server rejects a table reachable from
 * one parent by more than one cascade path, and uat_case_results is reachable
 * from uat_cycles via cases AND via participants. Cleanup is done explicitly in
 * UatCycle::cascadeDelete() instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('uat_cycles')) {
            Schema::create('uat_cycles', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('title');
                // What is under test — "Planning Website", "ENTECH M4".
                $table->string('system_name')->nullable();
                $table->text('description')->nullable();
                // "3rd cycle" in the source workbook.
                $table->unsignedSmallInteger('cycle_no')->default(1);
                $table->string('environment', 60)->default('Web');
                // [{label, url}] — the workbook's Links block.
                $table->text('links')->nullable();
                $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                $table->foreignId('qa_lead_id')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('dev_lead_id')->nullable()->constrained('users')->onDelete('no action');
                // draft | in_progress | on_hold | completed | signed_off | cancelled
                $table->string('status', 30)->default('draft');
                $table->date('start_date')->nullable();
                $table->date('target_signoff_date')->nullable();
                $table->date('go_live_date')->nullable();
                // When true the final sign-off stays locked until every approver
                // has accepted. The UWC pack worked this way.
                $table->boolean('signoff_requires_all')->default(true);
                // Non-critical items are excluded from the go-live readiness gate,
                // mirroring the checklist's "Non-critical for Go-Live" split.
                $table->boolean('gate_on_critical_only')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->index('status');
                $table->index(['company_id', 'status']);
            });
        }

        if (!Schema::hasTable('uat_sections')) {
            Schema::create('uat_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                $table->string('name');
                $table->string('description')->nullable();
                $table->boolean('is_critical')->default(true);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();

                $table->index(['uat_cycle_id', 'order']);
            });
        }

        if (!Schema::hasTable('uat_cases')) {
            Schema::create('uat_cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                $table->foreignId('uat_section_id')->nullable()->constrained('uat_sections')->onDelete('no action');
                // "UI-UX-01" in the workbook; auto-generated when left blank.
                $table->string('case_key', 40);
                // The workbook's Screen column — "Issuances - Department Orders".
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
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
                $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
                $table->timestamps();

                $table->unique(['uat_cycle_id', 'case_key']);
                $table->index(['uat_cycle_id', 'uat_section_id', 'order']);
            });
        }

        if (!Schema::hasTable('uat_participants')) {
            Schema::create('uat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('uat_cycle_id')->constrained('uat_cycles')->onDelete('no action');
                // department | stakeholder — an internal team column, or an
                // external client contact from the acceptance roster.
                $table->string('kind', 20)->default('department');
                // Matrix column header: "BD", "Ops Support", "Acctg".
                $table->string('label', 80);
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('no action');
                $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('no action');
                $table->string('contact_name')->nullable();
                $table->string('contact_email')->nullable();
                // tester | approver | observer. Approvers also sign off.
                $table->string('role', 20)->default('tester');
                // Tokenised, no-login access for external stakeholders.
                // Uniqueness is added below as a filtered index — SQL Server
                // treats NULL as a value in a unique index and would reject the
                // second participant that has no token yet.
                $table->string('access_token', 64)->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->timestamp('last_accessed_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();

                $table->index(['uat_cycle_id', 'order']);
                $table->index(['uat_cycle_id', 'role']);
            });

            // Only issued tokens have to be unique. Every other engine already
            // treats NULLs as distinct in a unique index; SQL Server does not,
            // so it gets an explicitly filtered one.
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlsrv') {
                DB::statement('CREATE UNIQUE INDEX uat_participants_access_token_unique ON uat_participants (access_token) WHERE access_token IS NOT NULL');
            } else {
                Schema::table('uat_participants', function (Blueprint $table) {
                    $table->unique('access_token', 'uat_participants_access_token_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('uat_participants');
        Schema::dropIfExists('uat_cases');
        Schema::dropIfExists('uat_sections');
        Schema::dropIfExists('uat_cycles');
    }
};
