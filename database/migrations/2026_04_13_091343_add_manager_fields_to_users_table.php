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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_manager')->default(false)->after('is_active');
        });

        Schema::create('manager_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Use no action for manager_id to avoid SQL Server circular reference issues
            $table->foreignId('manager_id')->constrained('users')->onDelete('no action');
            $table->primary(['user_id', 'manager_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manager_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_manager');
        });
    }
};
