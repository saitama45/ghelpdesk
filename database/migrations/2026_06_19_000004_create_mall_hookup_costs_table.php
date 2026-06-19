<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mall_hookup_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mall_hookup_id')->constrained('mall_hookups')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['mall_hookup_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_hookup_costs');
    }
};
