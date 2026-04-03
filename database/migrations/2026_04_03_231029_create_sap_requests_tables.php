<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('request_type_id')->constrained()->onDelete('cascade');
            $table->uuid('ticket_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('no action');
            $table->string('requester_name')->nullable();
            $table->string('requester_email')->nullable();
            $table->string('status')->default('Open');
            $table->integer('current_approval_level')->default(0);
            $table->json('form_data');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('no action');
        });

        Schema::create('sap_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sap_request_id')->constrained()->onDelete('cascade');
            $table->json('item_data');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sap_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sap_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('no action');
            $table->integer('level');
            $table->string('status')->default('approved');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_request_approvals');
        Schema::dropIfExists('sap_request_items');
        Schema::dropIfExists('sap_requests');
    }
};
