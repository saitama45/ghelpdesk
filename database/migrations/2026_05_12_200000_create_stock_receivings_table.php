<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receivings', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('stock_transfer_id')->constrained('stock_transfers')->onDelete('cascade');

            $blueprint->string('receiving_no')->nullable();
            $blueprint->date('receiving_date');

            $blueprint->string('origin_location');
            $blueprint->string('destination_location');

            $blueprint->foreignId('asset_id')->constrained()->onDelete('no action');
            $blueprint->integer('source_stock_in_id')->nullable();

            $blueprint->string('serial_no')->nullable();
            $blueprint->string('barcode')->nullable();
            $blueprint->text('qrcode')->nullable();

            $blueprint->string('asset_type')->default('New');
            $blueprint->boolean('is_allocation')->default(false);
            $blueprint->integer('warranty_months')->default(0);
            $blueprint->integer('eol_months')->default(0);
            $blueprint->decimal('cost', 18, 2)->default(0);
            $blueprint->decimal('price', 18, 2)->default(0);

            $blueprint->integer('transferred_quantity')->default(1);
            $blueprint->integer('received_quantity')->default(1);

            $blueprint->string('condition')->default('Good'); // Good, Damaged, Missing
            $blueprint->text('damage_notes')->nullable();

            $blueprint->string('status')->default('For Receiving'); // For Receiving, Received
            $blueprint->string('received_by')->nullable();
            $blueprint->dateTime('received_at')->nullable();

            $blueprint->text('remarks')->nullable();

            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['stock_transfer_id', 'status']);
            $blueprint->index(['destination_location', 'status']);
            $blueprint->index('receiving_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receivings');
    }
};
