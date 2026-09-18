<?php

namespace App\Support;

use App\Models\{Category, Cluster, Company, Department, Item, Store, SubCategory, Ticket, Vendor};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Explicit reference scopes: never a global scope on shared business records. */
final class DepartmentReferences
{
    public const CATALOGS = ['clusters', 'categories', 'sub_categories', 'items'];
    /** Only catalogues carry a department tag; stores and vendors are entity-wide. */
    public const MODELS = [
        'clusters' => Cluster::class, 'categories' => Category::class,
        'sub_categories' => SubCategory::class, 'items' => Item::class,
    ];

    public static function viewedId(): ?int
    {
        return auth()->check() ? DepartmentContext::resolveViewedId(auth()->user()) : null;
    }

    public static function canManage(?int $departmentId): bool
    {
        $user = auth()->user();
        return $user && ($user->can('departments.edit') || ($departmentId && DepartmentContext::homeDepartmentId($user) === $departmentId));
    }

    public static function management($query)
    {
        $table = $query->getModel()->getTable();
        // An entity with no service departments of its own — a brand reading its
        // entities' catalogue — keeps the whole inherited list, as it did before
        // references carried a tag. Filtering it by a department it cannot have
        // would empty the page.
        if (in_array($table, self::CATALOGS, true) && self::viewedId()) {
            // Untagged rows are visible for repair on management pages only.
            $query->where(fn ($q) => $q->where($table.'.department_id', self::viewedId())
                ->orWhereNull($table.'.department_id'));
        }

        return $query;
    }

    public static function selectable($query, ?int $departmentId)
    {
        $table = $query->getModel()->getTable();
        if (in_array($table, self::CATALOGS, true)) {
            return $departmentId ? $query->where($table.'.department_id', $departmentId) : $query->whereRaw('1 = 0');
        }

        // Stores and partners are the entity's own locations and suppliers: every
        // department serving that entity works with the same list, so they carry no
        // department tag and are never enabled department by department.
        return $query;
    }

    /** May the signed-in user move this catalogue row between departments? */
    public static function canRetag(Model $record): bool
    {
        return in_array($record->getTable(), self::CATALOGS, true)
            && EntityReferenceScope::isOwned($record)
            && (! $record->department_id || self::canManage((int) $record->department_id));
    }

    public static function unique(string $table, string $column = 'name', ?Model $record = null)
    {
        return Rule::unique($table, $column)->ignore($record?->getKey())
            ->where('company_id', $record ? $record->company_id : CompanyContext::activeCompanyId())
            ->where('department_id', $record ? $record->department_id : self::viewedId());
    }

    public static function ensureOwned(Model $record): void
    {
        if (in_array($record->getTable(), self::CATALOGS, true)) {
            if ($record->department_id) {
                abort_unless((int) $record->department_id === self::viewedId(), 404);
            }
            if (self::viewedId()) {
                abort_unless(self::canManage(self::viewedId()), 403);
            }
        }
    }

    public static function validateClassification(Model $item): void
    {
        if (! $item->department_id) {
            return; // Historical untagged rows remain repairable.
        }
        foreach (['category_id' => Category::class, 'sub_category_id' => SubCategory::class] as $field => $class) {
            if ($item->$field && ! $class::whereKey($item->$field)
                ->where('department_id', $item->department_id)->where('company_id', $item->company_id)->exists()) {
                throw ValidationException::withMessages([$field => 'Choose a reference tagged to the same entity and service department.']);
            }
        }
    }

    /** Validate the proposed final state; unchanged historical references survive unrelated edits. */
    public static function validateTicket(Ticket $ticket, bool $force = false): void
    {
        $fields = ['store_id', 'item_id', 'vendor_id', 'category_id', 'sub_category_id'];
        if (! $force && $ticket->exists && ! $ticket->isDirty([...$fields, 'company_id', 'serving_department_id'])) {
            return;
        }
        $item = $ticket->item_id ? Item::find($ticket->item_id) : null;
        // Automated classified sources can identify their desk through the selected
        // service item. An existing mailbox/form route always takes precedence.
        if (! $ticket->exists && ! TicketAccess::servingDepartmentId($ticket) && $item?->department_id) {
            $ticket->serving_department_id = $item->department_id;
        }
        // Mail/form intake with no classification continues to use its existing route.
        if (! $force && ! $ticket->item_id && ! $ticket->vendor_id && ! $ticket->category_id && ! $ticket->sub_category_id) {
            return;
        }

        $departmentId = TicketAccess::servingDepartmentId($ticket);
        $entityId = ($ticket->store_id ? Store::whereKey($ticket->store_id)->value('company_id') : null) ?: $ticket->company_id;
        if (! $departmentId) {
            // Retain installations/entities with no department configuration.
            if (! Department::whereIn('company_id', Company::itemSourceIds($entityId))->exists()) {
                return;
            }
            throw ValidationException::withMessages(['serving_department_id' => 'Select the service department before classifying this ticket.']);
        }
        $department = Department::find($departmentId);
        if (! $department || ! $department->is_active || ! EntityReferenceScope::fitsCompany($department->company_id, $entityId)) {
            throw ValidationException::withMessages(['serving_department_id' => 'The service department does not serve the selected entity.']);
        }
        if ($item) {
            self::validateClassification($item);
            $ticket->category_id = $item->category_id;
            $ticket->sub_category_id = $item->sub_category_id;
        }
        foreach (['store_id' => Store::class, 'vendor_id' => Vendor::class, 'item_id' => Item::class,
            'category_id' => Category::class, 'sub_category_id' => SubCategory::class] as $field => $class) {
            if (! $ticket->$field) {
                continue;
            }
            $row = self::selectable($class::query(), $departmentId)->whereKey($ticket->$field)->first();
            if (! $row || (isset($row->is_active) && ! $row->is_active)
                || ($field !== 'store_id' && ! EntityReferenceScope::fitsCompany($row->company_id, $entityId))) {
                throw ValidationException::withMessages([$field => 'This reference is not available to the ticket\'s service department and entity.']);
            }
        }
        // Freeze the legacy assignee fallback once a ticket is classified.
        $ticket->serving_department_id ??= $departmentId;
    }
}
