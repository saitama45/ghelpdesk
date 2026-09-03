<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('project_task_id')
                ->nullable()
                ->after('milestone_id')
                ->constrained('project_tasks')
                // SQL Server rejects SET NULL here because tickets and project
                // tasks already participate in other cascading relationships.
                ->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_task_id');
        });
    }
};
