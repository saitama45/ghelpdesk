<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQL Server, let's just change it. The driver handles column modification differently.
        Schema::table('pos_requests', function (Blueprint $table) {
            $table->string('status', 50)->default('Open')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_requests', function (Blueprint $table) {
            $table->enum('status', ['Open', 'Approved', 'Cancelled', 'In Progress', 'Resolved'])->default('Open')->change();
        });
    }
};
