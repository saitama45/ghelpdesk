<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stamp_card_id')->constrained('stamp_cards')->cascadeOnDelete();
            // Number of stamps added by this entry (always positive).
            $table->integer('quantity');
            // manual | purchase
            $table->string('source')->default('manual');
            // Captured when source = purchase (amount-based earning).
            $table->decimal('purchase_amount', 12, 2)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index('stamp_card_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_entries');
    }
};
