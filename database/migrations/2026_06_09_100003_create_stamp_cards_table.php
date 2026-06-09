<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('stamp_program_id')->constrained('stamp_programs');
            $table->integer('stamps_count')->default(0);
            // active | completed | redeemed (string for SQL Server compatibility)
            $table->string('status')->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index('status');
            $table->index(['customer_id', 'stamp_program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_cards');
    }
};
