<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_record_tenders', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('payment_record_id')->constrained('payment_records')->onDelete('cascade');
            // planned = the split proposed at submission, actual = money that actually moved
            $blueprint->string('kind', 16)->default('actual');
            // Mirrors reference_options(type = payment_mode).value
            $blueprint->string('mode', 100);
            $blueprint->decimal('amount', 18, 2)->default(0);
            $blueprint->decimal('share_percent', 8, 4)->nullable();
            $blueprint->date('paid_on')->nullable();
            $blueprint->string('reference_no', 100)->nullable();
            // Mode-specific fields (cheque no / bank, card last 4 / approval code, ...)
            $blueprint->json('details')->nullable();
            $blueprint->string('remarks', 255)->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['payment_record_id', 'kind']);
            $blueprint->index('mode');
            $blueprint->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_record_tenders');
    }
};
