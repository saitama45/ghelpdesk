<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision log behind the vendor approval matrix. `vendors.approved_by/at` only
 * remember the latest activation, so every approve/reject/suspend/reactivate is
 * recorded here with its approver and remarks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_approvals')) {
            return;
        }

        Schema::create('vendor_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('action', 20);              // approved | rejected | suspended | reactivated
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->cascadeOnDelete();
            // Deliberately no FK on decided_by: a second cascade path into
            // `users` is what SQL Server rejects, and the approval history must
            // outlive the staff account that decided it.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_approvals');
    }
};
