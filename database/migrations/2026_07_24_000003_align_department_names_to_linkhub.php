<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns department names/codes with the LINK Hub naming so the prototype and the
 * app read the same. Idempotent: each update is guarded on the old value, so it
 * is a no-op once applied (and safe to re-run on cloud via auto-migrate).
 *
 * Notes (not changed here): local "Operations" has no prototype equivalent, and
 * the prototype's FM / OWD / LD departments do not exist locally — left as data
 * decisions for the team.
 */
return new class extends Migration
{
    /** [oldName => [newName, newCode]] guarded renames. */
    private const RENAMES = [
        'Finance' => ['Finance and Accounting', 'F&A'],
        'People And Organization' => ['People and Organization', 'P&O'],
        'Technology And Solutions' => ['Technology and Solutions', 'TAS'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $oldName => [$newName, $code]) {
            DB::table('departments')->where('name', $oldName)->update([
                'name' => $newName,
                'code' => $code,
            ]);
        }

        // Marketing keeps its name; just backfill the missing code.
        DB::table('departments')
            ->where('name', 'Marketing')
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))
            ->update(['code' => 'MKTG']);
    }

    public function down(): void
    {
        foreach (self::RENAMES as $oldName => [$newName, $code]) {
            DB::table('departments')->where('name', $newName)->update([
                'name' => $oldName,
                'code' => $oldName === 'Finance' ? null : $code,
            ]);
        }

        DB::table('departments')->where('name', 'Marketing')->where('code', 'MKTG')->update(['code' => null]);
    }
};
