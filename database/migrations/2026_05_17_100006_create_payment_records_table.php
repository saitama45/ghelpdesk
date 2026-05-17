<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('payable_type'); // renewal, invoice, weekly
            $blueprint->unsignedBigInteger('payable_id');
            $blueprint->foreignId('vendor_id')->constrained('vendors')->onDelete('no action');
            $blueprint->decimal('amount', 18, 2)->default(0);
            $blueprint->date('paid_on')->nullable();
            $blueprint->string('reference_no')->nullable();
            $blueprint->unsignedBigInteger('paid_by')->nullable();
            $blueprint->index('paid_by');
            $blueprint->string('status')->default('pending'); // pending, approved, rejected, posted
            $blueprint->integer('current_approval_level')->default(0);
            $blueprint->json('approver_data')->nullable();
            $blueprint->text('remarks')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['payable_type', 'payable_id']);
            $blueprint->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
