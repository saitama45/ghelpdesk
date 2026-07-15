<?php

namespace App\Support;

use App\Models\Store;
use RuntimeException;

final class CfeTicketStore
{
    public const STORE_NAME = 'CFE I';

    public const COMPANY_CODE = 'TGI';

    public static function resolve(): Store
    {
        $store = Store::query()
            ->with('company')
            ->where(function ($query) {
                $query->where('name', self::STORE_NAME)
                    ->orWhere('code', self::STORE_NAME);
            })
            ->first();

        if (!$store) {
            throw new RuntimeException('Ticket creation requires the CFE I store, but it is not configured.');
        }

        if (!$store->company || strtoupper((string) $store->company->code) !== self::COMPANY_CODE) {
            throw new RuntimeException('The CFE I store must belong to the TGI company before tickets can be created.');
        }

        return $store;
    }
}
