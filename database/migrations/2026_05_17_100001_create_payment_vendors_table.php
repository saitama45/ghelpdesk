<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vendors', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('vendor_id')->unique()->constrained('vendors')->onDelete('cascade');
            $blueprint->string('default_payment_terms')->nullable();
            $blueprint->string('default_currency', 8)->default('PHP');
            $blueprint->string('billing_email')->nullable();
            $blueprint->text('notes')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vendors');
    }
};
