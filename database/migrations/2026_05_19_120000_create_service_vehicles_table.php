<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_vehicles', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('name');
            $blueprint->string('plate_no')->unique();
            $blueprint->unsignedInteger('capacity')->nullable();
            $blueprint->string('status')->default('active'); // active, maintenance, retired
            $blueprint->text('notes')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_vehicles');
    }
};
