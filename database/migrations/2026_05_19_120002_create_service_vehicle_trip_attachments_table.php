<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_vehicle_trip_attachments', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('service_vehicle_trip_id')->constrained('service_vehicle_trips')->cascadeOnDelete();
            $blueprint->string('file_name');
            $blueprint->string('file_storage_path');
            $blueprint->bigInteger('file_size_bytes')->default(0);
            $blueprint->foreignId('uploaded_by')->nullable()->constrained('users');
            $blueprint->timestamp('uploaded_date')->useCurrent();
            $blueprint->timestamps();

            $blueprint->index('service_vehicle_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_vehicle_trip_attachments');
    }
};
