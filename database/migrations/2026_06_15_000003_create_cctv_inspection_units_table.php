<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_inspection_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cctv_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_in_id')->constrained('stock_ins')->cascadeOnDelete();
            $table->string('condition')->default('Working')->comment('Working, Defective, N/A');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['cctv_inspection_id', 'stock_in_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_inspection_units');
    }
};
