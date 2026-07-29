<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays')) {
            return;
        }

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // regular | special_non_working | special_working | custom
            $table->string('type', 40)->default('regular');
            $table->date('date');
            // Fixed-date holidays (Jan 1, Dec 25, …) repeat every year, so only
            // the month/day matter. Movable ones (Holy Week, Eid, National
            // Heroes Day) are dated per year and are not recurring.
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('no action');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->index('date');
            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
