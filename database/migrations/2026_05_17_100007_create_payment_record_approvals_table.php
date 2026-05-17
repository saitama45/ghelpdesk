<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_record_approvals', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('payment_record_id')->constrained('payment_records')->onDelete('cascade');
            $blueprint->unsignedBigInteger('user_id');
            $blueprint->index('user_id');
            $blueprint->integer('level')->default(1);
            $blueprint->string('action')->default('approved'); // approved, rejected
            $blueprint->text('remarks')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_record_approvals');
    }
};
