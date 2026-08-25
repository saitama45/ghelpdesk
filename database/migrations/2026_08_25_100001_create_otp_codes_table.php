<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-issued email one-time codes for the mobile app's post-login
 * verification step (`POST /api/otp/send` / `POST /api/otp/verify`).
 *
 * Only the hash is ever stored — the plaintext code exists only for the
 * length of the request that emails it. `attempts` caps guessing per code;
 * `consumed_at` prevents replaying a code that already succeeded once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // The verify endpoint always wants "this user's newest code".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
