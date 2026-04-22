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
        Schema::create('stock_ins', function (Blueprint $app) {
            $app->id();
            $app->date('receive_date');
            $app->foreignId('asset_id')->constrained('assets')->onDelete('cascade');
            $app->integer('quantity')->default(1);
            $app->string('serial_no')->nullable();
            $app->integer('warranty_months')->default(0);
            $app->date('warranty_date')->nullable();
            $app->integer('eol_months')->default(0);
            $app->date('eol_date')->nullable();
            $app->decimal('cost', 15, 2)->default(0);
            $app->decimal('price', 15, 2)->default(0);
            $app->string('location')->nullable();
            $app->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ins');
    }
};
