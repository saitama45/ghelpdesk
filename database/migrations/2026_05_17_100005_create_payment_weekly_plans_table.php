<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_weekly_plans', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $blueprint->string('project_label')->nullable();
            $blueprint->string('month', 16)->nullable();
            $blueprint->integer('week_no')->nullable();
            $blueprint->date('week_date')->nullable();
            $blueprint->decimal('amount', 18, 2)->default(0);
            $blueprint->string('category')->nullable(); // POS, CCTV, Internet, Speaker, Anti-virus, Router, Google
            $blueprint->text('notes')->nullable();
            $blueprint->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->string('status')->default('Planned'); // Planned, Released, Paid
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index('week_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_weekly_plans');
    }
};
