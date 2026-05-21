<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('value', 100);
            $table->string('label', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index('type');
        });

        DB::table('reference_options')->insert([
            // project_type
            ['type' => 'project_type', 'value' => 'NSO',              'label' => 'NSO (New Store Opening)', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'project_type', 'value' => 'Store Closure',    'label' => 'Store Closure',           'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'project_type', 'value' => 'Store Renovation', 'label' => 'Store Renovation',        'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            // store_class
            ['type' => 'store_class', 'value' => 'Regular',                 'label' => 'Regular Store',          'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'store_class', 'value' => 'Kitchen',                 'label' => 'Kitchen Only',           'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'store_class', 'value' => 'Both',                    'label' => 'Both (Regular & Kitchen)', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'store_class', 'value' => 'Office',                  'label' => 'Office Store',           'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'store_class', 'value' => 'Department Store (DS)',    'label' => 'Department Store (DS)',  'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_options');
    }
};
