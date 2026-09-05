<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque details on the portal's company profile — how a cheque payment is made
 * out and released. The portal added these columns on the shared database; this
 * mirrors them so a standalone ghelpdesk database (and every test run) has them
 * too. Guarded per column, so it is a no-op wherever the portal migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_vendor_profiles')) {
            return;
        }

        Schema::table('portal_vendor_profiles', function (Blueprint $table) {
            foreach ([
                // The payee exactly as the bank will accept it.
                'cheque_payee_name' => fn () => $table->string('cheque_payee_name')->nullable(),
                'cheque_delivery_method' => fn () => $table->string('cheque_delivery_method', 30)->nullable(),
                'cheque_is_crossed' => fn () => $table->boolean('cheque_is_crossed')->default(false),
                'cheque_remarks' => fn () => $table->string('cheque_remarks', 500)->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('portal_vendor_profiles', $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversible: on the shared database these hold the vendor's own
        // cheque instructions.
        throw new RuntimeException('add_cheque_details_to_portal_vendor_profiles cannot be rolled back — the portal owns this table.');
    }
};
