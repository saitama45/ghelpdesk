<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_task_id')->nullable()->constrained('project_tasks')->onDelete('no action');
            $table->string('name');
            $table->string('category')->nullable(); // Admin, Pre-Work, POS, etc
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('support_by')->nullable()->constrained('users')->onDelete('no action');
            $table->string('status')->default('Pending'); // Pending, Ongoing, Done, N/A
            $table->integer('progress')->default(0); // 0-100
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('original_end_date')->nullable();
            $table->json('dependencies')->nullable(); // Array of task IDs
            $table->text('comments')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
