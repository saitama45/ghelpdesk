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
            $table->enum('role', ['super_admin', 'admin', 'property_manager', 'accountant', 'agent', 'staff', 'tenant'])->default('staff')->after('email');
            $table->string('department')->nullable()->after('role');
            $table->string('position')->nullable()->after('department');
            $table->dateTime('last_login')->nullable()->after('position');
            $table->string('profile_photo')->nullable()->after('last_login');
            $table->text('permissions')->nullable()->after('profile_photo');

            $table->index(['role']);
            $table->index(['department', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'department',
                'position',
                'last_login',
                'profile_photo',
                'permissions'
            ]);
        });
    }
};
