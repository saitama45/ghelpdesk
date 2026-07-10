<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving a ticket (soft delete) previously recorded no actor at all, so a POS
     * request whose ticket vanished could not explain who archived it. Nullable because
     * tickets archived before this migration have no attributable user.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('deleted_by')->nullable()->after('is_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('deleted_by');
        });
    }
};
