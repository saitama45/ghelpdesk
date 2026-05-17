<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_overpayments', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('vendor_id')->constrained('vendors')->onDelete('no action');
            $blueprint->date('collection_date')->nullable();
            $blueprint->string('check_details')->nullable();
            $blueprint->decimal('amount', 18, 2)->default(0);
            $blueprint->text('remarks')->nullable();
            $blueprint->unsignedBigInteger('applied_to_invoice_id')->nullable();
            $blueprint->index('applied_to_invoice_id');
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_overpayments');
    }
};
