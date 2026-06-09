<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stamp_card_id')->constrained('stamp_cards');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('stamp_program_id')->constrained('stamp_programs');
            // The redeemed reward item (an Asset, type=Consumables).
            $table->foreignId('asset_id')->constrained('assets');
            $table->string('location');
            $table->integer('quantity')->default(1);
            // Link back to the inventory ledger row that recorded the deduction.
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index('asset_id');
            $table->index('customer_id');
            $table->index('inventory_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_redemptions');
    }
};
