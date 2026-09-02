<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_redemption_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stamp_redemption_id')->index();
            $table->unsignedBigInteger('stock_in_id')->unique();
            $table->string('serial_no')->nullable();
            $table->string('barcode')->nullable();
            $table->text('qrcode')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_redemption_units');
    }
};
