<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an archived customer whose personal data was erased because it holds
 * financial records (reward redemptions, voucher payments) and so can never be
 * purged. Set by AccountArchiveService::anonymizeCustomer; `accounts:purge-expired`
 * skips rows that carry it, and Settings → Account Archive stops listing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'anonymized_at')) {
                $table->dropColumn('anonymized_at');
            }
        });
    }
};
