<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODES = [
        'Credit Card',
        'Cheque',
        'Bank Transfer',
        'Cash on Delivery (COD)',
        'Cash',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::MODES as $index => $mode) {
            $exists = DB::table('reference_options')
                ->where('type', 'payment_mode')
                ->where('value', $mode)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('reference_options')->insert([
                'type' => 'payment_mode',
                'value' => $mode,
                'label' => $mode,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('reference_options')
            ->where('type', 'payment_mode')
            ->whereIn('value', self::MODES)
            ->delete();
    }
};
