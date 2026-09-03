<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('no action');
            $table->string('partner_name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('face_value', 12, 2);
            $table->date('turnover_date')->nullable();
            $table->date('claim_starts_on')->nullable();
            $table->date('claim_ends_on')->nullable();
            $table->string('claim_instructions', 255)->nullable();
            $table->string('short_terms', 500)->nullable();
            $table->string('partner_logo_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('no action');
            $table->text('cancel_reason')->nullable();
            $table->string('pdf_status', 20)->default('not_generated');
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->foreignId('pdf_requested_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('created_by')->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_batch_id')->constrained('voucher_batches')->onDelete('cascade');
            $table->string('code', 40)->unique();
            $table->string('status', 20)->default('issued');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->onDelete('no action');
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['voucher_batch_id', 'status']);
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->onDelete('no action');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('no action');
            $table->foreignId('store_id')->constrained('stores')->onDelete('no action');
            $table->string('receipt_number', 100);
            $table->date('sale_date');
            $table->decimal('gross_sale_total', 12, 2);
            $table->decimal('applied_amount', 12, 2);
            $table->decimal('forfeited_amount', 12, 2)->default(0);
            $table->timestamp('redeemed_at');
            $table->foreignId('redeemed_by')->constrained('users')->onDelete('no action');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->onDelete('no action');
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['voucher_id', 'voided_at']);
            $table->index(['store_id', 'sale_date', 'receipt_number']);
        });

        // A claim exists only while a redemption is active. Deleting it during
        // a manager reversal allows the receipt to be corrected while the
        // immutable redemption row remains available for audit.
        Schema::create('voucher_sale_claims', function (Blueprint $table) {
            $table->string('sale_key', 191)->primary();
            $table->foreignId('voucher_redemption_id')->unique()->constrained('voucher_redemptions')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('voucher_verification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->string('scanned_code', 255);
            $table->string('result', 30);
            $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('no action');
            $table->foreignId('verified_by')->constrained('users')->onDelete('no action');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['result', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_verification_attempts');
        Schema::dropIfExists('voucher_sale_claims');
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_batches');
    }
};
