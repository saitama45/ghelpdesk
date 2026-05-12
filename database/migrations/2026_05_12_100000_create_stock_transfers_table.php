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
        Schema::create('stock_transfers', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->date('transfer_date');
            $blueprint->string('transfer_no')->nullable();
            $blueprint->string('origin_location');
            $blueprint->string('destination_location');
            $blueprint->string('requested_by')->nullable();
            $blueprint->string('memo_remarks', 2000)->nullable();
            $blueprint->string('posted_by')->nullable();
            $blueprint->dateTime('posted_date')->nullable();
            $blueprint->string('status')->default('For Posting');

            $blueprint->foreignId('asset_id')->constrained()->onDelete('no action');
            $blueprint->integer('source_stock_in_id')->nullable();
            $blueprint->string('asset_type')->default('New');
            $blueprint->boolean('is_allocation')->default(false);
            $blueprint->integer('quantity')->default(1);

            $blueprint->string('serial_no')->nullable();
            $blueprint->string('barcode')->nullable();
            $blueprint->text('qrcode')->nullable();

            $blueprint->integer('warranty_months')->default(0);
            $blueprint->date('warranty_date')->nullable();
            $blueprint->integer('eol_months')->default(0);
            $blueprint->date('eol_date')->nullable();

            $blueprint->decimal('cost', 18, 2)->default(0);
            $blueprint->decimal('price', 18, 2)->default(0);

            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
