<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('Open');
            $table->string('priority')->default('Medium');
            $table->timestampTz('created_date')->useCurrent();
            $table->timestampTz('modified_date')->useCurrent();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE tickets ADD ticket_number INT IDENTITY(1000,1) NOT NULL CONSTRAINT UQ_Tickets_TicketNumber UNIQUE');
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Status CHECK (status IN ('Open', 'Waiting', 'Closed'))");
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT CK_Tickets_Priority CHECK (priority IN ('Low', 'Medium', 'High'))");
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE tickets ADD ticket_number INT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE');
        } else {
            // Fallback for SQLite or others: just a column, app must handle increment
            Schema::table('tickets', function (Blueprint $table) {
                $table->integer('ticket_number')->nullable()->unique();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};