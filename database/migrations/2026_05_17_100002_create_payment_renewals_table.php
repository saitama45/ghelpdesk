<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_renewals', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $blueprint->string('service_type');
            $blueprint->string('sub_type')->nullable();
            $blueprint->string('purpose')->nullable();
            $blueprint->decimal('unit_cost', 18, 2)->default(0);
            $blueprint->integer('qty')->default(1);
            $blueprint->decimal('total_amount', 18, 2)->default(0);
            $blueprint->string('currency', 8)->default('PHP');
            $blueprint->string('cycle')->default('monthly'); // monthly, quarterly, annual, semi_annual
            $blueprint->date('cycle_anchor_date')->nullable();
            $blueprint->date('next_due_date')->nullable();
            $blueprint->date('expiration_date')->nullable();
            $blueprint->string('payment_terms')->nullable();
            $blueprint->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->string('status')->default('active'); // active, paused, cancelled
            $blueprint->text('notes')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index('next_due_date');
            $blueprint->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_renewals');
    }
};
