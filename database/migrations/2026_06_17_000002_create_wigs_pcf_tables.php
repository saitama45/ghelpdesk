<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WIGS — Performance Commitment Form (PCF) header + items, and the quarterly
 * Performance Appraisal Form (PAF) scores.
 *
 * - One PCF per user per year (self-service entry).
 * - PCF items are the WIGs rows with per-quarter weights.
 * - PAF is auto-generated from PCF items; grading is captured per quarter in
 *   wigs_paf_scores. The annual PAF score is computed (avg of quarters).
 *
 * SQL Server: avoid multiple cascade paths. The user FKs use "no action";
 * child tables cascade from their parent only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wigs_pcf', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->unsignedSmallInteger('year');
            // Org snapshot at time of commitment (text, not FK — org may change)
            $table->string('level_1')->nullable();
            $table->string('level_2')->nullable();
            $table->string('level_3')->nullable();
            $table->string('status')->default('draft'); // draft | confirmed
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });

        Schema::create('wigs_pcf_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcf_id')->constrained('wigs_pcf')->cascadeOnDelete();
            $table->string('kra')->nullable();                 // Key Result Area
            $table->text('wig')->nullable();                   // Wildly Important Goal
            $table->text('lead_measures')->nullable();
            $table->string('performance_standard')->nullable(); // snapshot of wigs_performance_standards.general
            $table->text('performance_metric')->nullable();     // formula text
            $table->text('metric_benchmark')->nullable();       // Total/Avg Qtr Metric Benchmark
            $table->decimal('q1_weight', 5, 2)->default(0);     // percent, e.g. 10.00
            $table->decimal('q2_weight', 5, 2)->default(0);
            $table->decimal('q3_weight', 5, 2)->default(0);
            $table->decimal('q4_weight', 5, 2)->default(0);
            $table->string('value_alignment')->nullable();      // snapshot of wigs_track_values.name
            $table->text('value_remarks')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wigs_paf_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcf_item_id')->constrained('wigs_pcf_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter'); // 1-4
            $table->text('actual_performance')->nullable();
            $table->unsignedTinyInteger('rating')->nullable(); // 1-4
            $table->boolean('value_pass')->nullable();          // value-based Yes/No
            $table->text('remarks')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['pcf_item_id', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wigs_paf_scores');
        Schema::dropIfExists('wigs_pcf_items');
        Schema::dropIfExists('wigs_pcf');
    }
};
