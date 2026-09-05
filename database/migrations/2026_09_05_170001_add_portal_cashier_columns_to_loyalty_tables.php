<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty activity can now originate from the vendor portal's Campaigns module,
 * where the actor is a Cashier **vendor** rather than a back-office user. Every
 * `*_by` column on these tables is an FK to `users`, which a vendor has no row
 * in, so each table gains a parallel nullable `cashier_vendor_id`.
 *
 * Two of those user columns were also NOT NULL, which would have made a portal
 * write impossible outright; they become nullable here. Nothing is dropped and
 * no row is rewritten — existing hub records keep their user attribution and
 * simply carry a null vendor.
 */
return new class extends Migration
{
    /** table => the column the new FK is placed after. */
    private const TABLES = [
        'stamp_cards' => 'updated_by',
        'stamp_entries' => 'created_by',
        'stamp_redemptions' => 'updated_by',
        'voucher_redemptions' => 'redeemed_by',
        'voucher_verification_attempts' => 'verified_by',
    ];

    /** table => the NOT NULL user column that has to accept null now. */
    private const RELAXED = [
        'voucher_redemptions' => 'redeemed_by',
        'voucher_verification_attempts' => 'verified_by',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'cashier_vendor_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($after) {
                $blueprint->foreignId('cashier_vendor_id')->nullable()->after($after)
                    ->constrained('vendors')->onDelete('no action');
            });
        }

        foreach (self::RELAXED as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->makeNullable($table, $column);
        }
    }

    /**
     * SQL Server will not alter a column while a foreign key sits on it, so the
     * constraint is dropped, the column relaxed, and the constraint put back
     * exactly as it was. Other drivers (SQLite under test) are left alone —
     * they build these tables from the original migration each run and nothing
     * in the test suite writes a portal-attributed row.
     */
    private function makeNullable(string $table, string $column): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $constraint = "{$table}_{$column}_foreign";
        $exists = DB::selectOne(
            'SELECT 1 AS found FROM sys.foreign_keys WHERE name = ?',
            [$constraint]
        );

        if ($exists) {
            DB::statement("ALTER TABLE [{$table}] DROP CONSTRAINT [{$constraint}]");
        }

        DB::statement("ALTER TABLE [{$table}] ALTER COLUMN [{$column}] BIGINT NULL");

        if ($exists) {
            DB::statement(
                "ALTER TABLE [{$table}] ADD CONSTRAINT [{$constraint}] "
                . "FOREIGN KEY ([{$column}]) REFERENCES [users]([id])"
            );
        }
    }

    public function down(): void
    {
        // Forward-only: dropping these columns would discard the attribution of
        // every loyalty transaction made from the vendor portal.
        throw new RuntimeException('add_portal_cashier_columns_to_loyalty_tables cannot be rolled back.');
    }
};
