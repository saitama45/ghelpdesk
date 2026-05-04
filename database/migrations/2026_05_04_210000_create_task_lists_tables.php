<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_boards', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('background_type')->default('color');
            $table->text('background_value')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('no action');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('task_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->string('role')->default('member');
            $table->boolean('starred')->default(false);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['task_board_id', 'user_id']);
        });

        Schema::create('task_board_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['task_board_id', 'user_id']);
        });

        Schema::create('task_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('color')->default('gray');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('Backlogs');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('due_reminder_minutes')->nullable();
            $table->boolean('due_complete')->default(false);
            $table->string('cover_type')->nullable();
            $table->text('cover_value')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('no action');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_board_id', 'status', 'archived_at', 'sort_order']);
        });

        Schema::create('task_card_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['task_card_id', 'user_id']);
        });

        Schema::create('task_card_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->onDelete('no action');
            $table->foreignId('task_label_id')->constrained('task_labels')->onDelete('no action');
            $table->timestamps();

            $table->unique(['task_card_id', 'task_label_id']);
        });

        Schema::create('task_card_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->timestamps();

            $table->unique(['task_card_id', 'user_id']);
        });

        Schema::create('task_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->cascadeOnDelete();
            $table->string('title')->default('Checklist');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_checklist_id')->constrained('task_checklists')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_complete')->default(false);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_card_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->onDelete('no action');
            $table->text('comment_text');
            $table->timestamps();
        });

        Schema::create('task_card_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_card_id')->constrained('task_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            $table->text('file_storage_path');
            $table->bigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        Schema::create('task_card_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('task_card_id')->nullable()->constrained('task_cards')->onDelete('no action');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('description');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['task_board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_card_activities');
        Schema::dropIfExists('task_card_attachments');
        Schema::dropIfExists('task_card_comments');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_checklists');
        Schema::dropIfExists('task_card_watchers');
        Schema::dropIfExists('task_card_label');
        Schema::dropIfExists('task_card_assignees');
        Schema::dropIfExists('task_cards');
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('task_board_watchers');
        Schema::dropIfExists('task_board_members');
        Schema::dropIfExists('task_boards');
    }
};
