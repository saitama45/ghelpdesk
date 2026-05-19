<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_vehicle_trips', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('service_vehicle_id')->constrained('service_vehicles')->cascadeOnDelete();
            // SQL Server disallows multiple cascade paths when several FKs target the same table.
            // Keep driver_id and approved_by as NO ACTION (default); deletion of a driver is rare and handled explicitly.
            $blueprint->foreignId('driver_id')->constrained('users');
            $blueprint->date('date_used');
            $blueprint->string('purpose_of_travel');
            $blueprint->text('passengers')->nullable();
            $blueprint->string('start_point');
            $blueprint->string('end_point');
            $blueprint->time('planned_departure_time');
            $blueprint->time('planned_arrival_time');
            $blueprint->time('actual_departure_time')->nullable();
            $blueprint->time('actual_arrival_time')->nullable();
            $blueprint->unsignedInteger('odometer_before')->nullable();
            $blueprint->unsignedInteger('odometer_after')->nullable();
            $blueprint->text('remarks')->nullable();
            $blueprint->string('status')->default('Pending Approval'); // Pending Approval, Scheduled, In Progress, Completed, Rejected, Cancelled
            $blueprint->foreignId('approved_by')->nullable()->constrained('users');
            $blueprint->timestamp('approved_at')->nullable();
            $blueprint->text('rejection_reason')->nullable();
            $blueprint->boolean('acknowledgement_accepted')->default(false);
            $blueprint->timestamp('acknowledged_at')->nullable();
            $blueprint->integer('created_by')->nullable();
            $blueprint->integer('updated_by')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['date_used', 'service_vehicle_id']);
            $blueprint->index('status');
            $blueprint->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_vehicle_trips');
    }
};
