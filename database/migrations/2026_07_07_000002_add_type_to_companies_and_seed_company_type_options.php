<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds a manageable "type" (Entity / Brand / …) to companies, sourced from the
 * shared reference_options table (type = company_type). Existing companies
 * default to "Entity". This is metadata only — it does NOT affect the
 * active-entity switching logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('type', 100)->default('Entity')->after('code');
        });

        // Backfill any pre-existing rows explicitly (belt-and-suspenders alongside
        // the column default, in case the driver leaves older rows null).
        DB::table('companies')->whereNull('type')->update(['type' => 'Entity']);

        // Seed the selectable company types (skip if already present).
        $now = now();
        $seed = [
            'Entity' => 1,
            'Brand'  => 2,
        ];

        foreach ($seed as $value => $sortOrder) {
            $exists = DB::table('reference_options')
                ->where('type', 'company_type')->where('value', $value)->exists();

            if (! $exists) {
                DB::table('reference_options')->insert([
                    'type'       => 'company_type',
                    'value'      => $value,
                    'label'      => $value,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        DB::table('reference_options')
            ->where('type', 'company_type')
            ->whereIn('value', ['Entity', 'Brand'])
            ->delete();
    }
};
