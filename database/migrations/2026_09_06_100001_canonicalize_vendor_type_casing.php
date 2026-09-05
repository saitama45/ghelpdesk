<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor portal used to write its slug labels straight through
 * ('supplier'), so a handful of rows hold a case variant of a managed option.
 *
 * That was survivable while the field was a plain dropdown, but now that the
 * list is authoritative it is not: the option list matches on an exact string,
 * so such a row opens with an EMPTY Vendor Type and its `in:` validation
 * rejects the value it already holds. Both are fixed by folding each row onto
 * the canonical spelling of the option it already means.
 *
 * Case-only rewrites, no row loses its type. SQL Server's default collation is
 * case-insensitive, so the comparison is forced to a binary collation —
 * otherwise the WHERE matches every row and the UPDATE is a silent no-op that
 * looks like it worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The binary-collation comparison below is SQL Server syntax, and this
        // drift only exists in the shared production-shaped database anyway.
        if (! Schema::hasTable('vendors') || DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $canonical = DB::table('reference_options')
            ->where('type', 'vendor_type')
            ->pluck('value');

        foreach ($canonical as $value) {
            DB::table('vendors')
                ->whereRaw('vendor_type COLLATE Latin1_General_BIN <> ?', [$value])
                ->where('vendor_type', $value) // case-insensitive: same option, different spelling
                ->update(['vendor_type' => $value]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the previous spelling carried no meaning the
        // canonical one does not.
    }
};
