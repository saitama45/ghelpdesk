<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('cc_role_id')->nullable();
            $blueprint->string('global_bcc')->nullable();
            $blueprint->string('default_currency', 8)->default('PHP');
            $blueprint->integer('approval_levels')->default(2);
            $blueprint->json('approver_user_ids')->nullable();
            $blueprint->boolean('reminders_enabled')->default(true);
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
