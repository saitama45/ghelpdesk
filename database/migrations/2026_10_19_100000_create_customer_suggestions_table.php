<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile loyalty app's Suggestion Box (Profile → Suggestion Box), read by
 * staff on /stamps → Suggestions.
 *
 * Every foreign key is `no action`: a member's suggestions are removed with
 * them by AccountArchiveService when the account is purged, rather than by a
 * cascade SQL Server would refuse alongside users → customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('no action');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('no action');
            $table->string('subject', 120)->nullable();
            $table->text('message');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_suggestions');
    }
};
