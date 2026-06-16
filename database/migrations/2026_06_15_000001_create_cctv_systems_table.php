<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cctv_type')->nullable()->comment('DVR, NVR, Hybrid');
            $table->boolean('has_qr_code')->default(false);
            $table->boolean('setup_completed')->default(false);
            $table->string('dpo_seal_checking')->default('Pending')->comment('Pending, Done, N/A');
            $table->unsignedInteger('dvr_nvr_count')->nullable();
            $table->unsignedInteger('expected_cameras')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_systems');
    }
};
