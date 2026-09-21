<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_receivings', function (Blueprint $table) {
            // Photo of the paper DR, kept as a visual guide. Stamped on every row of the
            // receiving group; the file lives on the private "local" disk.
            $table->string('dr_image_path', 500)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('stock_receivings', function (Blueprint $table) {
            $table->dropColumn('dr_image_path');
        });
    }
};
