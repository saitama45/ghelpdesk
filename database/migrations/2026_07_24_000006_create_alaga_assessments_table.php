<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALAGA store IT-asset assessments (the LINK Hub "ALAGA Asset Assessment"): a
 * TAS-led per-store scorecard rating IT asset condition, lifecycle, and preventive
 * actions on a /4.0 scale. Equipment-category scores and the inspection checklist
 * are stored as JSON on the assessment (no separate child tables) for simplicity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alaga_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // SQL Server allows only one cascade/set-null path to a table; the user
            // references use "no action" (identity snapshotted by relation loads).
            $table->unsignedBigInteger('inspector_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable(); // active entity (owning brand)
            $table->date('assessment_date');
            $table->decimal('overall_score', 3, 2)->default(0);   // /4.00
            $table->string('status')->default('Fair');            // Excellent | Good | Fair
            $table->json('asset_scores')->nullable();             // [{category, score}]
            $table->json('checklist')->nullable();                // [{parameter, standard, finding, score}]
            $table->text('observations')->nullable();
            $table->text('recommendations')->nullable();
            $table->date('next_review')->nullable();
            $table->string('workflow_status')->default('Completed'); // Requested | In Progress | Completed
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('inspector_id')->references('id')->on('users')->onDelete('no action');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('no action');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('no action');
            $table->index(['company_id', 'assessment_date']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alaga_assessments');
    }
};
