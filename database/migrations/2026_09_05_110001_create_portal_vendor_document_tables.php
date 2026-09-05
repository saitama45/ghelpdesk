<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor accreditation documents live in the vendor portal's tables on the
 * shared database — linkportal creates and writes them, this app only reads
 * them on /vendors.
 *
 * Mirrors the two tables here for the same reason the vendors portal columns
 * are mirrored: a standalone ghelpdesk database (and every test run) still has
 * to satisfy `App\Models\VendorDocument`. Both creates are guarded, so this is
 * a no-op on the shared database where the portal already made them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_reference_options')) {
            Schema::create('portal_reference_options', function (Blueprint $table) {
                $table->id();
                $table->string('type', 50)->index();
                $table->string('value', 100);
                $table->string('label');
                $table->text('meta')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('portal_vendor_documents')) {
            return;
        }

        Schema::create('portal_vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->string('title');
            // Portal-relative path on the portal's own public disk — the file
            // itself is never stored by this app.
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('version')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: on the shared database these tables
        // belong to the vendor portal and hold its uploaded documents.
        throw new RuntimeException('create_portal_vendor_document_tables cannot be rolled back — the portal owns these tables.');
    }
};
