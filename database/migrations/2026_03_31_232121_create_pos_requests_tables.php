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
        Schema::create('pos_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('request_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('no action'); // Avoid cycle
            $table->date('launch_date');
            $table->date('effectivity_date');
            $table->json('stores_covered'); 
            $table->enum('status', ['Open', 'Approved', 'Cancelled', 'In Progress', 'Resolved'])->default('Open');
            $table->integer('current_approval_level')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_request_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_request_id')->constrained()->onDelete('cascade');
            $table->string('product_name');
            $table->string('pos_name');
            $table->text('remarks_mechanics')->nullable();
            $table->string('price_type'); // Use string for broader compatibility if needed, but enum is fine if specified
            $table->string('category')->nullable();
            $table->string('item_code')->nullable();
            $table->string('sc')->nullable();
            $table->string('local_tax')->nullable();
            $table->string('mgr_meal')->nullable();
            $table->string('printer')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('no action'); // Avoid cycle
            $table->integer('level');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_request_approvals');
        Schema::dropIfExists('pos_request_details');
        Schema::dropIfExists('pos_requests');
    }
};
