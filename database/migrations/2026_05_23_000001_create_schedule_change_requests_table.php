<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->onDelete('no action');
            $table->json('assigned_approver_ids');
            $table->string('status')->default('pending');
            $table->json('original_payload');
            $table->json('requested_payload');
            $table->text('requester_remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('rejected_at')->nullable();
            $table->text('approver_remarks')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'requester_id', 'status'], 'schedule_change_request_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_change_requests');
    }
};
