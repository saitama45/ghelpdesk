<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor portal's scan-to-stamp flow now captures the POS receipt the
 * stamps were earned on, the same way a voucher redemption does. Nullable:
 * every existing entry, and the hub's manual stamping, has no receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stamp_entries', 'receipt_number')) {
            return;
        }

        Schema::table('stamp_entries', function (Blueprint $table) {
            $table->string('receipt_number', 100)->nullable()->after('purchase_amount');
        });
    }

    public function down(): void
    {
        // Forward-only: dropping the column would discard every recorded receipt.
        throw new RuntimeException('add_receipt_number_to_stamp_entries_table cannot be rolled back.');
    }
};
