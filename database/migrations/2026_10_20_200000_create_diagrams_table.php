<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagrams', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description', 500)->nullable();
            // private = creator only; department = the creator's "I belong to" department; everyone.
            $table->string('visibility', 20)->default('department');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            // JSON scene { pages: [{ id, name, background, grid, elements }] } — LOB, read only by
            // the editor. `preview` is a small SVG for the list cards (shown via <img>, never inlined).
            $table->longText('scene')->nullable();
            $table->longText('preview')->nullable();
            // Optimistic-concurrency counter: a save names the revision it started from and is
            // refused with 409 when someone else saved in between.
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedSmallInteger('page_count')->default(1);
            // Plain columns, no FK: a diagram must never block deleting a user.
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
        Schema::dropIfExists('diagrams');
    }
};
