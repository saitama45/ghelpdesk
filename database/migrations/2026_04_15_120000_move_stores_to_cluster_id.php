<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->after('class')->constrained('clusters')->noActionOnDelete();
        });

        $existingClusters = DB::table('clusters')->get(['id', 'code', 'name']);
        $lookup = [];
        foreach ($existingClusters as $cluster) {
            $lookup[mb_strtolower(trim($cluster->name))] = $cluster->id;
            $lookup[mb_strtolower(trim($cluster->code))] = $cluster->id;
        }

        $storeClusters = DB::table('stores')
            ->select('id', 'cluster')
            ->whereNotNull('cluster')
            ->where('cluster', '!=', '')
            ->get();

        $createdCodes = collect($existingClusters)->pluck('code')->filter()->map(fn ($code) => strtoupper($code))->all();

        foreach ($storeClusters as $store) {
            $value = trim((string) $store->cluster);
            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            $clusterId = $lookup[$key] ?? null;

            if (!$clusterId) {
                $base = Str::upper(Str::slug(Str::limit($value, 40, ''), '-'));
                $base = $base !== '' ? $base : 'CLUSTER';
                $code = $base;
                $suffix = 1;

                while (in_array($code, $createdCodes, true)) {
                    $code = Str::limit($base, 45, '') . '-' . $suffix;
                    $suffix++;
                }

                $clusterId = DB::table('clusters')->insertGetId([
                    'code' => $code,
                    'name' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $createdCodes[] = $code;
                $lookup[$key] = $clusterId;
                $lookup[mb_strtolower($code)] = $clusterId;
            }

            DB::table('stores')
                ->where('id', $store->id)
                ->update(['cluster_id' => $clusterId]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('cluster');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('cluster')->nullable()->after('class');
        });

        $stores = DB::table('stores')
            ->leftJoin('clusters', 'stores.cluster_id', '=', 'clusters.id')
            ->select('stores.id', 'clusters.name as cluster_name')
            ->get();

        foreach ($stores as $store) {
            DB::table('stores')
                ->where('id', $store->id)
                ->update(['cluster' => $store->cluster_name]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cluster_id');
        });

    }
};
