<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Entity switching for reference MANAGEMENT pages
 * (/items, /stores, /clusters, /vendors, /categories, /sub-categories).
 *
 * - visible(): what a page lists — the active company's own rows, the rows of
 *   every Entity it is tagged to on /companies (a brand inherits its entities'
 *   references, see Company::itemSourceIds), and rows with no company (shared).
 * - owned() / ensureOwned(): what a page may change — only the active company's
 *   own rows and shared rows. Inherited rows are read-only and 404 by URL.
 *
 * Deliberately applied in controllers, NOT as a global scope: ticket forms,
 * email intake, dashboards and other modules keep resolving these references
 * across every entity (CompanyContext keeps reference models unscoped). New rows
 * are stamped with the active entity by the creating listener in
 * AppServiceProvider. With no active entity (console, queue) nothing is filtered.
 */
final class EntityReferenceScope
{
    /** Company ids whose rows the active company sees (itself + tagged entities). */
    public static function visibleCompanyIds(): array
    {
        return Company::itemSourceIds(CompanyContext::activeCompanyId());
    }

    public static function visible($query, string $column = 'company_id')
    {
        $ids = self::visibleCompanyIds();

        if ($ids === []) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereIn($column, $ids)->orWhereNull($column));
    }

    public static function owned($query, string $column = 'company_id')
    {
        $companyId = CompanyContext::activeCompanyId();

        if (! $companyId) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where($column, $companyId)->orWhereNull($column));
    }

    public static function isOwned(Model $model, string $column = 'company_id'): bool
    {
        $companyId = CompanyContext::activeCompanyId();
        $rowCompanyId = $model->getAttribute($column);

        return ! $companyId || $rowCompanyId === null || (int) $rowCompanyId === $companyId;
    }

    /**
     * Company ids whose rows are visible to ANY of the given companies (each one
     * plus the entities it is tagged to). Empty input means "no restriction".
     */
    public static function visibleCompanyIdsFor(array $companyIds): array
    {
        return collect($companyIds)
            ->filter()
            ->flatMap(fn ($id) => Company::itemSourceIds((int) $id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Company ids whose STORES the given companies may pick from.
     *
     * Stores roll UP the entity_brand tag — the opposite direction to items and
     * the other references. A brand INHERITS its entities' catalogue (that is
     * {@see visibleCompanyIdsFor}), but an entity OWNS its brands' locations:
     * TGI operates the CBTL, NONO'S and DEMPSEY stores, so a ticket raised under
     * TGI must be able to name any of them.
     *
     * Only the companies passed in are expanded to their brands, never the
     * entities they inherit from — otherwise CBTL, which inherits TGI, would
     * also see its SIBLING brands' stores.
     */
    public static function storeCompanyIdsFor(array $companyIds): array
    {
        $inherited = self::visibleCompanyIdsFor($companyIds);

        if ($inherited === []) {
            return [];
        }

        $brandsByEntity = self::brandIdsByEntity();

        $brands = collect($companyIds)
            ->filter()
            ->flatMap(fn ($id) => $brandsByEntity[(int) $id] ?? []);

        return collect($inherited)->concat($brands)->unique()->values()->all();
    }

    /** entity company id => ids of the brands tagged to it (one query, for per-row lists). */
    public static function brandIdsByEntity(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('entity_brand')
            ->get(['entity_company_id', 'brand_company_id'])
            ->groupBy('entity_company_id')
            ->map(fn ($rows) => $rows->pluck('brand_company_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Which companies may USE a row owned by $ownerCompanyId: the owner plus every
     * brand tagged to it. null = shared row, usable by everyone. Sent to ticket
     * pickers as `usable_company_ids` (resources/js/lib/entityItems.js).
     */
    public static function usableCompanyIds($ownerCompanyId, \Illuminate\Support\Collection $brandsByEntity): ?array
    {
        if (! $ownerCompanyId) {
            return null;
        }

        return array_values(array_unique([(int) $ownerCompanyId, ...($brandsByEntity[(int) $ownerCompanyId] ?? [])]));
    }

    /**
     * Whether a row owned by $ownerCompanyId may be used for a ticket whose store
     * (or, without one, ticket) belongs to $storeCompanyId.
     */
    public static function fitsCompany($ownerCompanyId, $storeCompanyId): bool
    {
        return ! $ownerCompanyId
            || ! $storeCompanyId
            || in_array((int) $ownerCompanyId, Company::itemSourceIds((int) $storeCompanyId), true);
    }

    /** URL guard for update/delete/approval actions on another entity's row. */
    public static function ensureOwned(Model $model, string $column = 'company_id'): void
    {
        abort_unless(self::isOwned($model, $column), 404);
    }
}
