<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My timezone" for staff working outside the Philippines. /schedules and /dtr
 * show and accept times in this IANA zone; schedules are still stored and
 * reported in Asia/Manila. NULL means Asia/Manila, so every existing user is
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'timezone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Forward-only: dropping the column would discard every saved preference.
        throw new RuntimeException('add_timezone_to_users_table cannot be rolled back.');
    }
};
