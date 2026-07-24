<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the asset condition + procurement lifecycle to ticket_assets, enabling the
 * LINK Hub ticket→asset→inventory→procurement chain. `soh_at_store` (stock on hand)
 * is already computed by TicketAssetController, so the routing signal exists; these
 * columns capture the human input (condition, "for purchase") and the resulting
 * procurement state that fans out to Store Health and Inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_assets', 'condition')) {
                // Healthy | For Checking | Not Working | For Replacement
                $table->string('condition')->nullable()->after('transaction_type');
            }
            if (! Schema::hasColumn('ticket_assets', 'purchase_required')) {
                $table->boolean('purchase_required')->default(false)->after('condition');
            }
            if (! Schema::hasColumn('ticket_assets', 'procurement_status')) {
                // null | Pending Approval | Approved | Incoming | Received | For Setup | Deployed
                $table->string('procurement_status')->nullable()->after('purchase_required');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_assets', function (Blueprint $table) {
            foreach (['condition', 'purchase_required', 'procurement_status'] as $col) {
                if (Schema::hasColumn('ticket_assets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
