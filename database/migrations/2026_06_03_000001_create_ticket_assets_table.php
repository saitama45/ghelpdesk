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
        Schema::create('ticket_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_id');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            // Specific physical unit = source StockIn row id (Fixed assets). Null for Consumables.
            // No DB-level FK: SQL Server rejects the extra cascade path (assets→stock_ins→ticket_assets
            // alongside assets→ticket_assets). serial_no/barcode are snapshotted so the link survives
            // unit deletion regardless.
            $table->unsignedBigInteger('stock_in_id')->nullable();
            $table->string('serial_no')->nullable(); // snapshot at tag time
            $table->string('barcode')->nullable();   // snapshot at tag time
            $table->string('transaction_type'); // PM, Repair, Stock Out, Stock In, Deployment
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
            // Uniqueness enforced in the controller (SQL Server allows only one NULL per unique key).
            $table->index('asset_id');
            $table->index('stock_in_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_assets');
    }
};
