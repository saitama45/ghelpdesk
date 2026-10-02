<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first report_key migration (2026_09_15_000001) tagged DAVID items by
     * exact name once. Most of them (Orders, Commits, Receiving, Sales Upload,
     * Admin / Technical Concerns) were created after it ran and later renamed
     * (Orders -> Order, Wastages -> Wastage, the two Admin items -> one), so only
     * MEC ever reached DAVID's Success Rate tab.
     *
     * The key is now set per item on /items, which survives any rename. This
     * one-time backfill tags today's items under the David sub-category by
     * their old or new names. Like the first one it only fills empty keys, so
     * nothing set on /items is overwritten. Inactive items are tagged too:
     * their past tickets still belong in the tally.
     */
    private const NAMES = [
        'order' => 'david.order',
        'orders' => 'david.order',
        'commit' => 'david.commit',
        'commits' => 'david.commit',
        'receiving' => 'david.receiving',
        'wastage' => 'david.wastage',
        'wastages' => 'david.wastage',
        'mec' => 'david.mec',
        'sales upload' => 'david.sales_upload',
    ];

    /** "Admin / Technical Concerns", "... - Internet", "... - Laptop", "... -" */
    private const ADMIN_PREFIX = 'admin / technical concerns';

    public function up(): void
    {
        $subCategoryIds = DB::table('sub_categories')->where('name', 'David')->pluck('id');

        if ($subCategoryIds->isEmpty()) {
            return;
        }

        $items = DB::table('items')
            ->whereIn('sub_category_id', $subCategoryIds)
            ->whereNull('report_key')
            ->get(['id', 'name']);

        foreach ($items as $item) {
            $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $item->name)));
            $key = self::NAMES[$name] ?? (str_starts_with($name, self::ADMIN_PREFIX) ? 'david.admin' : null);

            if ($key !== null) {
                DB::table('items')->where('id', $item->id)->whereNull('report_key')->update(['report_key' => $key]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: keys are data an admin may have confirmed on /items since.
    }
};
