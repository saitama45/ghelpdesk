<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Picture library for whiteboards (Elements panel → Images): pictures people uploaded
     * once (e.g. a CCTV camera model) and drag onto any board. Shared pictures are listed
     * for everyone; the rest only for the uploader. Files live in storage/app/public.
     */
    public function up(): void
    {
        Schema::create('canvas_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->boolean('shared')->default(true);
            // Plain column, no FK: a library picture must never block deleting a user.
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['shared', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_assets');
    }
};
