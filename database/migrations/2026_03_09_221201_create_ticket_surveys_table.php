<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // SQL Server doesn't like unique indexes on nullable columns if there's more than one NULL.
            // We'll add it without unique for now to be safe, or we can use a filtered index if we wanted.
            $table->string('survey_token', 64)->nullable();
        });

        Schema::create('ticket_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('tickets')->onDelete('cascade');
            $table->integer('rating')->comment('1-Poor, 2-Fair, 3-Good, 4-Excellent');
            $table->text('feedback')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_surveys');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('survey_token');
        });
    }
};
