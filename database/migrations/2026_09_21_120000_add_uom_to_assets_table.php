<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure on assets. Stock stays counted in the base UOM (one stock row = one piece);
 * the optional bulk UOM (BOX of 12 PC) is a grouping layer used when receiving and labelling.
 * Both lists live in reference_options so /assets can add or rename them inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Existing assets become plain pieces: no bulk UOM, behaviour unchanged.
            $table->string('base_uom', 30)->default('PC');
            $table->string('bulk_uom', 30)->nullable();
            $table->unsignedInteger('units_per_bulk')->nullable();
        });

        $now = now();
        $seed = [
            'uom_base' => ['PC', 'UNIT', 'SET', 'PAIR', 'ROLL', 'REAM', 'METER', 'LITER'],
            'uom_bulk' => ['BOX', 'PACK', 'CASE', 'CARTON', 'BUNDLE', 'DOZEN'],
        ];

        foreach ($seed as $type => $values) {
            foreach ($values as $index => $value) {
                $exists = DB::table('reference_options')->where('type', $type)->where('value', $value)->exists();
                if (! $exists) {
                    DB::table('reference_options')->insert([
                        'type' => $type,
                        'value' => $value,
                        'label' => $value,
                        'sort_order' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Forward-only: dropping these columns would discard every asset's UOM.
        throw new RuntimeException('add_uom_to_assets_table cannot be rolled back.');
    }
};
