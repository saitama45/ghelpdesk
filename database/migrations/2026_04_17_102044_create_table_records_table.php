<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function run(): void
    {
        Schema::create('table_records', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('table_definition_id')->constrained('table_definitions')->onDelete('cascade');
            $blueprint->text('data'); // Using text/nvarchar(max) for SQL Server JSON
            $blueprint->string('status')->default('pending');
            $blueprint->foreignId('created_by')->nullable()->constrained('users');
            $blueprint->foreignId('updated_by')->nullable()->constrained('users');
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_records');
    }
};
