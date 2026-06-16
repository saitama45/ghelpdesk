<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cctv_system_id')->constrained()->cascadeOnDelete();
            $table->date('inspection_date');
            $table->string('overall_status')->default('Pending')->comment('Working, Not Working, For Schedule, On-going, Pending');
            $table->unsignedInteger('working_cameras')->nullable();
            $table->unsignedInteger('not_working_cameras')->nullable();
            $table->unsignedInteger('total_cameras')->nullable();
            $table->string('technician')->nullable();
            $table->string('data_retention')->nullable()->comment('e.g. 40 days');
            $table->string('storage')->nullable()->comment('e.g. 5TB');
            $table->string('ups_status')->nullable();
            $table->string('lgu_memo')->nullable();
            $table->string('lgu_status')->default('Pending')->comment('Compliant, Non-Compliant, Pending, N/A');
            $table->text('next_step')->nullable();
            $table->text('remarks')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->boolean('is_latest')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cctv_system_id', 'inspection_date']);
        });

        Schema::table('cctv_inspections', function (Blueprint $table) {
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_inspections');
    }
};
