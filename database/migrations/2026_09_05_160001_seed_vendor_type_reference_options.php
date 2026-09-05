<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the vendor type list out of the `Vendor::TYPES` constant and into the
 * shared reference_options table, so /vendors can add, rename and remove types
 * the same way /activity-templates manages project types.
 *
 * "Cashier" joins the seed: a Cashier is a linkportal login that runs the
 * Campaigns (loyalty stamps) module for one store, which is why `store_id`
 * is added to `vendors` here too — it is the store every card, stamp and
 * voucher redemption made from the portal is booked against.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $seed = [
            'Supplier' => 1,
            'Service Provider' => 2,
            'Contractor' => 3,
            'Consultant' => 4,
            'Logistics / Forwarder' => 5,
            'Cashier' => 6,
        ];

        foreach ($seed as $value => $sortOrder) {
            $exists = DB::table('reference_options')
                ->where('type', 'vendor_type')->where('value', $value)->exists();

            if (! $exists) {
                DB::table('reference_options')->insert([
                    'type' => 'vendor_type',
                    'value' => $value,
                    'label' => $value,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Any type already sitting on a vendor row (portal self-registration can
        // have written one that is not in the seed) becomes a managed option too,
        // otherwise editing that vendor would silently clear its type.
        if (Schema::hasTable('vendors')) {
            $orphans = DB::table('vendors')
                ->whereNotNull('vendor_type')
                ->where('vendor_type', '<>', '')
                ->distinct()
                ->pluck('vendor_type')
                ->reject(fn ($value) => array_key_exists($value, $seed));

            $sortOrder = count($seed);
            foreach ($orphans as $value) {
                $exists = DB::table('reference_options')
                    ->where('type', 'vendor_type')->where('value', $value)->exists();

                if (! $exists) {
                    DB::table('reference_options')->insert([
                        'type' => 'vendor_type',
                        'value' => $value,
                        'label' => $value,
                        'sort_order' => ++$sortOrder,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (! Schema::hasColumn('vendors', 'store_id')) {
                Schema::table('vendors', function (Blueprint $table) {
                    // Nullable and no-action on purpose: only Cashier accounts
                    // carry a store, and removing a store must never cascade
                    // into portal login rows.
                    $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('no action');
                });
            }
        }
    }

    public function down(): void
    {
        // Forward-only: dropping vendors.store_id would discard the store every
        // portal cashier is bound to.
        throw new RuntimeException('seed_vendor_type_reference_options cannot be rolled back.');
    }
};
