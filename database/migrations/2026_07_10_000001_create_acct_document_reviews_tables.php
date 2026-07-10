<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Accounting Document Reviews: inbox for vendor documents handed off from
// linkportal. Separate from the TAS Payments & SOA module (payment_*).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acct_document_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique(); // lp-doc-{id}-s{n}
            $table->unsignedBigInteger('source_document_id'); // linkportal portal_intake_documents.id
            $table->string('source_reference_no', 30)->index();
            $table->string('document_type', 30); // invoice, purchase_order, quotation
            $table->string('vendor_code', 50)->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('company_name')->nullable();
            $table->json('fields')->nullable(); // validated header fields from linkportal
            $table->json('line_items')->nullable();
            $table->json('confidence')->nullable(); // overall + per-field scores
            $table->json('exceptions_summary')->nullable(); // open warnings at submit time
            $table->text('file_url')->nullable(); // signed URL into linkportal
            $table->timestamp('file_url_expires_at')->nullable();
            $table->text('callback_url')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending, in_review, approved, returned, rejected
            $table->string('decision', 20)->nullable(); // approve, return, reject
            $table->text('decision_remarks')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamp('decided_at')->nullable();
            $table->string('callback_status', 20)->nullable(); // pending, sent, failed
            $table->unsignedInteger('callback_attempts')->default(0);
            $table->text('callback_error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'document_type']);
        });

        Schema::create('acct_document_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('acct_document_reviews')->onDelete('cascade');
            $table->string('event', 50); // received, opened, approved, returned, rejected, callback_sent, callback_failed
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('remarks')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acct_document_review_events');
        Schema::dropIfExists('acct_document_reviews');
    }
};
