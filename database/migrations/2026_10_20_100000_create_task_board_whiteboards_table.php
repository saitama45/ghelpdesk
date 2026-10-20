<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_board_whiteboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->unique()->constrained('task_boards')->cascadeOnDelete();
            // JSON scene: { pages: [{ id, name, background, elements: [...] }] }. Kept out of
            // the board payload and read only by the whiteboard page (LOB columns are slow over Azure).
            $table->longText('scene')->nullable();
            // Optimistic-concurrency counter: a save names the revision it started from and is
            // refused with 409 when someone else saved in between.
            $table->unsignedInteger('revision')->default(0);
            // Plain column, no FK: a whiteboard edit must never block deleting a user.
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_whiteboards');
    }
};
