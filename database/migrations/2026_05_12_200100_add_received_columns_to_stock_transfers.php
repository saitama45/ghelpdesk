<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $blueprint) {
            if (!Schema::hasColumn('stock_transfers', 'received_by')) {
                $blueprint->string('received_by')->nullable()->after('posted_date');
            }
            if (!Schema::hasColumn('stock_transfers', 'received_at')) {
                $blueprint->dateTime('received_at')->nullable()->after('received_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $blueprint) {
            if (Schema::hasColumn('stock_transfers', 'received_at')) {
                $blueprint->dropColumn('received_at');
            }
            if (Schema::hasColumn('stock_transfers', 'received_by')) {
                $blueprint->dropColumn('received_by');
            }
        });
    }
};
