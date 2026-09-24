<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `otp_codes` now serves two flows for the mobile app: the post-login
 * verification step (`login`) and the signed-out "Forgot Password" reset
 * (`password_reset`). Each flow only ever reads and retires codes of its own
 * purpose, so requesting one kind never cancels an outstanding code of the
 * other. Existing rows are all login codes, which the default covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('purpose', 32)->default('login')->after('user_id');
            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'purpose', 'created_at']);
            $table->dropColumn('purpose');
        });
    }
};
