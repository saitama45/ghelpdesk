<?php

use App\Models\Company;
use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Map each store's free-text `brand` to a real entity (company) and store
     * it in stores.company_id. Mirrors the brand→company matching used in
     * CctvMonitoringController: normalise both sides and match on code/name
     * (so "CBTL-DS" → CBTL, "Nonos" → NONO'S, etc.). Unmatched brands keep
     * whatever company_id they already had (TGI default from the earlier backfill).
     */
    public function up(): void
    {
        $normalize = fn (?string $v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        $companies = Company::all(['id', 'code', 'name']);

        Store::query()->select('id', 'brand', 'company_id')->chunkById(200, function ($stores) use ($normalize, $companies) {
            foreach ($stores as $store) {
                $target = $normalize($store->brand);
                if ($target === '') {
                    continue;
                }

                $company = $companies->first(function ($c) use ($normalize, $target) {
                    $code = $normalize($c->code);
                    $name = $normalize($c->name);

                    return ($code !== '' && (str_starts_with($target, $code) || str_starts_with($code, $target)))
                        || $name === $target;
                });

                if ($company && $store->company_id !== $company->id) {
                    $store->company_id = $company->id;
                    $store->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible: original company_id values are not recoverable.
    }
};
