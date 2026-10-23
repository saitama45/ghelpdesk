<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday slots and a per-member card limit for stamp programs.
 *
 * - `stamp_programs.holiday_stamps`: how many of a card's `stamps_required`
 *   slots only a Holiday stamp may fill (the paper holiday card: 4 "Holiday
 *   Drink" circles + 16 "Any Drink"). 0 = none, which is every program today.
 * - `stamp_programs.max_cards_per_customer`: how many cards one member may
 *   ever hold for the program, redeemed ones included. NULL = no limit.
 * - `stamp_cards.holiday_count`: holiday slots filled on the card. The
 *   regular count is `stamps_count - holiday_count`, so every existing reader
 *   of `stamps_count` keeps meaning "stamps on this card".
 * - `stamp_entries.stamp_type`: the slot type the entry's stamps landed in.
 *
 * Additive, with defaults that reproduce today's behaviour exactly. Both the
 * hub and the LINK portal write these tables; the portal never migrates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stamp_programs', 'holiday_stamps')) {
            Schema::table('stamp_programs', function (Blueprint $table) {
                $table->integer('holiday_stamps')->default(0)->after('stamps_required');
                $table->integer('max_cards_per_customer')->nullable()->after('holiday_stamps');
            });
        }

        if (! Schema::hasColumn('stamp_cards', 'holiday_count')) {
            Schema::table('stamp_cards', function (Blueprint $table) {
                $table->integer('holiday_count')->default(0)->after('stamps_count');
            });
        }

        if (! Schema::hasColumn('stamp_entries', 'stamp_type')) {
            Schema::table('stamp_entries', function (Blueprint $table) {
                $table->string('stamp_type', 20)->default('regular')->after('quantity');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: dropping these would discard which stamps were Holiday.
        throw new RuntimeException('add_holiday_slots_and_card_limit_to_loyalty_tables cannot be rolled back.');
    }
};
