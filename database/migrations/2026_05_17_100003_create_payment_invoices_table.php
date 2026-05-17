<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_invoices', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $blueprint->string('apv_no')->nullable();
            $blueprint->string('store_code')->nullable();
            $blueprint->string('po_number')->nullable();
            $blueprint->string('si_number')->nullable();
            $blueprint->date('si_date')->nullable();
            $blueprint->date('due_date')->nullable();
            $blueprint->decimal('invoice_amount', 18, 2)->default(0);
            $blueprint->decimal('outstanding_amount', 18, 2)->default(0);
            $blueprint->string('currency', 8)->default('PHP');
            $blueprint->string('status')->default('Pending'); // Pending, Due, Overdue, Paid, Cancelled
            $blueprint->text('remarks')->nullable();
            $blueprint->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index('due_date');
            $blueprint->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};
