<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing catalogue rows predate the department axis, so they carry no tag and
 * disappear from every ticket picker. They all belong to the desk that has been
 * running the helpdesk until now — Technology and Solutions — so they are tagged
 * to that department of their own entity. Only rows left untagged are touched;
 * anything a department has already claimed keeps its owner.
 */
return new class extends Migration
{
    /** Department id of "TAS" per entity, by company id. */
    public static function defaultDepartments(): array
    {
        return DB::table('departments')
            ->where(fn ($q) => $q->whereRaw('UPPER(code) = ?', ['TAS'])
                ->orWhereRaw('UPPER(name) = ?', ['TECHNOLOGY AND SOLUTIONS']))
            ->orderBy('id')
            ->get(['id', 'company_id'])
            ->keyBy('company_id')
            ->map(fn ($row) => (int) $row->id)
            ->all();
    }

    /** Identity columns guarded by each catalogue's filtered unique index. */
    private const IDENTITY = [
        'clusters' => ['name', 'code'],
        'categories' => ['name'],
        'sub_categories' => ['name'],
        'items' => ['category_id', 'sub_category_id', 'concern_type', 'name'],
    ];

    /**
     * A name that repeats among untagged rows — or already exists on a tagged row —
     * would violate the catalogue's unique index halfway through the backfill.
     * Report every clash by name so it can be renamed before deploying again.
     */
    private function collisions(string $table, int $companyId, int $departmentId): array
    {
        $clashes = [];
        foreach (self::IDENTITY[$table] as $column) {
            $columns = $table === 'items' ? self::IDENTITY[$table] : [$column];
            $rows = DB::table($table)->whereNull('department_id')->where('company_id', $companyId)
                ->select($columns)->get();
            foreach ($rows as $index => $row) {
                $match = DB::table($table)->where('company_id', $companyId)
                    ->where(fn ($q) => $q->where('department_id', $departmentId)->orWhereNull('department_id'));
                foreach ((array) $row as $field => $value) {
                    $match->where($field, $value);
                }
                if ($match->count() > 1) {
                    $clashes[] = $table.' #'.($index + 1).': '.implode(' / ', array_map('strval', (array) $row));
                }
            }
            if ($table === 'items') {
                break; // The items index is one composite identity, not per column.
            }
        }

        return array_unique($clashes);
    }

    public function up(): void
    {
        $departments = self::defaultDepartments();

        $clashes = [];
        foreach (array_keys(self::IDENTITY) as $table) {
            foreach ($departments as $companyId => $departmentId) {
                $clashes = [...$clashes, ...$this->collisions($table, (int) $companyId, $departmentId)];
            }
        }
        if ($clashes !== []) {
            throw new RuntimeException('Rename these duplicate references before tagging them: '.implode('; ', array_slice($clashes, 0, 20)));
        }

        foreach (['clusters', 'categories', 'sub_categories', 'items'] as $table) {
            foreach ($departments as $companyId => $departmentId) {
                DB::table($table)->whereNull('department_id')
                    ->where('company_id', $companyId)
                    ->update(['department_id' => $departmentId]);
            }
            // Entity-less legacy rows can only be placed when one desk owns the tag.
            if (count($departments) === 1) {
                $companyId = (int) array_key_first($departments);
                DB::table($table)->whereNull('department_id')->whereNull('company_id')
                    ->update(['department_id' => reset($departments), 'company_id' => $companyId]);
            }
        }
    }

    public function down(): void
    {
        // Clearing the tags again would hide every reference from the ticket
        // pickers. Retagging is done on the reference pages, not by a rollback.
        throw new RuntimeException('Reference department tags are edited on the reference pages, not reverted.');
    }
};
