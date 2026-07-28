<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_records', function (Blueprint $blueprint) {
            if (! Schema::hasColumn('payment_records', 'paid_amount')) {
                // Running total of posted (actual) tenders — drives partial payments.
                $blueprint->decimal('paid_amount', 18, 2)->default(0)->after('amount');
            }
        });

        Schema::table('vendors', function (Blueprint $blueprint) {
            if (! Schema::hasColumn('vendors', 'default_payment_mode')) {
                $blueprint->string('default_payment_mode', 100)->nullable();
            }
            if (! Schema::hasColumn('vendors', 'default_payment_split')) {
                // [{ mode, share_percent }, ...] — pre-fills the tender editor
                $blueprint->json('default_payment_split')->nullable();
            }
        });

        // Already-posted records are fully paid by definition.
        DB::table('payment_records')->where('status', 'posted')->update([
            'paid_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_records', function (Blueprint $blueprint) {
            if (Schema::hasColumn('payment_records', 'paid_amount')) {
                $blueprint->dropColumn('paid_amount');
            }
        });

        Schema::table('vendors', function (Blueprint $blueprint) {
            foreach (['default_payment_mode', 'default_payment_split'] as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
