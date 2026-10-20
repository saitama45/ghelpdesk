<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standalone whiteboards (/whiteboards), shared by visibility like diagrams. The
     * task-board whiteboard keeps its own table (task_board_whiteboards).
     */
    public function up(): void
    {
        Schema::create('whiteboards', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description', 500)->nullable();
            // private = creator only; department = the creator's "I belong to" department; everyone.
            $table->string('visibility', 20)->default('department');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            // JSON scene (LOB) read only by the editor; `preview` is a small SVG for list cards.
            $table->longText('scene')->nullable();
            $table->longText('preview')->nullable();
            // Optimistic-concurrency counter (stale saves get 409 and merge).
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedSmallInteger('page_count')->default(1);
            // Plain columns, no FK: a whiteboard must never block deleting a user.
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['visibility', 'department_id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboards');
    }
};
