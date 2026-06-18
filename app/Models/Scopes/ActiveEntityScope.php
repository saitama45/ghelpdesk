<?php

namespace App\Models\Scopes;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries on transactional module models to the active entity
 * (selected company) of the current user.
 *
 * - When there is no active entity (e.g. console commands, queued jobs, or a
 *   user with no company access) the scope is a no-op, so background work and
 *   admin tooling keep seeing everything.
 * - The column is table-qualified so the scope is safe inside joins.
 * - Bypass with ->withoutGlobalScope(ActiveEntityScope::class) for queries that
 *   must span entities (e.g. ticket-key number generation).
 */
class ActiveEntityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = CompanyContext::activeCompanyId();

        if ($companyId) {
            $builder->where($model->getTable() . '.company_id', $companyId);
        }
    }
}
