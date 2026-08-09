<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving is a soft delete kept separate from the existing hard delete:
     * approved records get filed away (restorable) instead of destroyed.
     * archived_by has no FK — user rows are removed elsewhere and SQL Server
     * rejects the extra cascade path.
     */
    public function up(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['archived_at', 'archived_by']);
        });
    }
};
