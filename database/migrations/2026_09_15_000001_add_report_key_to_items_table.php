<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stable reporting key on items, so an external report can find "its" items
     * without depending on ids (which differ between local and cloud) or names
     * (which get renamed).
     *
     * The DAVID app's Success Rate tab pulls its weekly Incoming/Closed ticket
     * counts through /api/integrations/david/ticket-tally, which buckets tickets
     * by `david.{module}`. Several items may share one key (Internet and Laptop
     * both roll up into Admin / Technical Concerns).
     */
    private const DAVID_ITEMS = [
        'Orders' => 'david.order',
        'Commits' => 'david.commit',
        'Receiving' => 'david.receiving',
        'Wastages' => 'david.wastage',
        'MEC' => 'david.mec',
        'Sales Upload' => 'david.sales_upload',
        'Admin / Technical Concerns - Internet' => 'david.admin',
        'Admin / Technical Concerns - Laptop' => 'david.admin',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('items', 'report_key')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('report_key', 64)->nullable()->index();
            });
        }

        $subCategoryIds = DB::table('sub_categories')->where('name', 'David')->pluck('id');

        if ($subCategoryIds->isEmpty()) {
            return;
        }

        // Only fills keys that are still empty, so re-running never overwrites a
        // mapping someone corrected by hand. "Wastages Concern (No Stock)" is
        // deliberately left unmapped.
        foreach (self::DAVID_ITEMS as $name => $key) {
            DB::table('items')
                ->whereIn('sub_category_id', $subCategoryIds)
                ->where('name', $name)
                ->whereNull('report_key')
                ->update(['report_key' => $key]);
        }
    }

    public function down(): void
    {
        // Forward-only: the column is additive and harmless to leave in place.
    }
};
