<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminder_log', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('payable_type'); // renewal, invoice
            $blueprint->unsignedBigInteger('payable_id');
            $blueprint->string('reminder_type'); // 30d, 7d, 1d, due, overdue
            $blueprint->date('window_date'); // the due-date this reminder is for (lets overdue re-send daily)
            $blueprint->dateTime('sent_at');
            $blueprint->json('recipients')->nullable();
            $blueprint->timestamps();

            $blueprint->unique(['payable_type', 'payable_id', 'reminder_type', 'window_date'], 'payment_reminder_log_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminder_log');
    }
};
