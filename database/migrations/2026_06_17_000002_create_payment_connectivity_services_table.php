<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_connectivity_services', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $blueprint->string('role')->default('primary'); // primary, secondary
            $blueprint->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $blueprint->string('telco')->nullable();         // PLDT, Innove, Converge, Smartlink, ...
            $blueprint->string('account_no')->nullable();
            $blueprint->string('service_id')->nullable();
            $blueprint->string('bandwidth')->nullable();     // "50 Mbps"
            $blueprint->string('install_type')->nullable();  // copper, fiber, wireless
            $blueprint->date('installation_date')->nullable();
            $blueprint->unsignedTinyInteger('billing_day')->nullable(); // 1-31; defaults to install-date day
            $blueprint->decimal('mrc', 12, 2)->default(0);   // Monthly Recurring Charge (VAT inc)
            $blueprint->string('currency', 8)->default('PHP');
            $blueprint->string('status')->default('active'); // active, pending, terminated
            $blueprint->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $blueprint->text('cc_emails')->nullable();
            $blueprint->text('notes')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index('store_id');
            $blueprint->index('status');
            $blueprint->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_connectivity_services');
    }
};
