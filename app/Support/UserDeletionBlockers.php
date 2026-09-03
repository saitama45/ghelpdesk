<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Why a user account cannot be deleted, said in the words of the person deleting it.
 *
 * `UserController@destroy` clears every reference it knows about, but the schema
 * keeps growing and each new `onDelete('no action')` foreign key eventually reaches
 * the admin as a raw `SQLSTATE[23000] ... conflicted with the REFERENCE constraint
 * "customers_created_by_foreign"` page. Rather than extend a hand-kept list forever,
 * this asks the database which columns still point at the user and names them.
 *
 * The scan is run INSIDE the delete transaction, after the cleanup — so it reports
 * what will genuinely block the DELETE, not references `destroy()` was about to
 * clear anyway.
 */
class UserDeletionBlockers
{
    /**
     * Where a person can go and deal with the rows that are in the way. A table
     * that is not listed still reports precisely; it just falls back to a
     * humanised version of its own name.
     */
    /** How many areas the toast names before it starts counting the rest. */
    private const NAMED_IN_MESSAGE = 4;

    private const LOCATIONS = [
        'customers' => ['Customers', 'Stamps → Customers tab'],
        'stamp_programs' => ['Loyalty Programs', 'Stamps → Programs tab'],
        'stamp_cards' => ['Stamp Cards', 'Stamps → Cards tab'],
        'stamp_entries' => ['Stamp Entries', 'Stamps → Cards tab'],
        'stamp_redemptions' => ['Stamp Redemptions', 'Stamps → Redemptions tab'],
        'voucher_batches' => ['Voucher Batches', 'Stamps → Vouchers tab'],
        'voucher_redemptions' => ['Voucher Payments', 'Stamps → Vouchers tab'],
        'voucher_verification_attempts' => ['Voucher Verification Attempts', 'Stamps → Vouchers tab'],
        'project_tasks' => ['Project Tasks', 'Project Tracker'],
        'inventory_transactions' => ['Inventory Transactions', 'Inventory'],
        'ticket_assets' => ['Ticket Assets', 'Tickets → Affected Assets'],
        'holidays' => ['Holidays', 'Administrative → Holidays'],
        'schedule_change_requests' => ['Schedule Change Requests', 'Scheduling → Requests tab'],
        'kb_article_views' => ['KB Article Views', 'Administrative → KB Articles'],
        'schedules' => ['Schedules', 'Administrative → Scheduling'],
        'attendance_logs' => ['Attendance Logs', 'Administrative → Attendance Logs'],
        'stock_ins' => ['Stock In Records', 'Inventory → Stock In'],
        'tickets' => ['Tickets', 'Services → Tickets'],
        'ticket_comments' => ['Ticket Replies', 'Services → Tickets'],
        'task_boards' => ['Task Boards', 'Services → Task Board'],
        'task_cards' => ['Task Cards', 'Services → Task Board'],
        'task_card_activities' => ['Task Card Activity', 'Services → Task Board'],
        'task_checklist_items' => ['Task Checklist Items', 'Services → Task Board'],
        'pos_requests' => ['POS Requests', 'Services → POS Requests'],
        'sap_requests' => ['SAP Requests', 'Services → SAP Requests'],
        'form_records' => ['Form Submissions', 'References → Form Builder'],
        'npc_statuses' => ['NPC Statuses', 'Monitoring → NPC Status'],
        'cctv_systems' => ['CCTV Systems', 'Monitoring → CCTV'],
        'cctv_inspections' => ['CCTV Inspections', 'Monitoring → CCTV'],
        'alaga_assessments' => ['ALAGA Assessments', 'Monitoring → ALAGA'],
        'acct_document_reviews' => ['Accounting Document Reviews', 'Monitoring → Accounting Documents'],
        'uat_cycles' => ['UAT Cycles', 'Administrative → UAT Tracker'],
        'uat_cases' => ['UAT Cases', 'Administrative → UAT Tracker'],
        'uat_findings' => ['UAT Findings', 'Administrative → UAT Tracker'],
        'stores' => ['Stores', 'References → Stores'],
        'store_user' => ['Assigned Stores', 'User Management → Assigned Stores'],
    ];

    /**
     * Every (table, column) still holding a reference to this user.
     *
     * @return array<int, array{table: string, column: string, count: int, label: string, location: ?string}>
     */
    public function for(User $user): array
    {
        $columns = $this->referencingColumns();

        if (empty($columns)) {
            return [];
        }

        // One statement, not one per foreign key. There are ~80 columns pointing at
        // `users`, and counting them individually took minutes over the SQL Server
        // link — long enough that the delete button looked hung.
        $connection = Schema::getConnection();
        $grammar = $connection->getQueryGrammar();

        $selects = [];
        $bindings = [];

        foreach ($columns as [$table, $column]) {
            $selects[] = sprintf(
                'SELECT %s AS referencing_table, %s AS referencing_column, COUNT(*) AS total FROM %s WHERE %s = ?',
                $this->literal($table),
                $this->literal($column),
                $grammar->wrapTable($table),
                $grammar->wrap($column)
            );
            $bindings[] = $user->id;
        }

        // Grouped per table, not per column: "8 in Customers" is what a person can
        // act on, where "4 in customers.created_by; 4 in customers.updated_by" is
        // just the schema talking.
        $blockers = collect($connection->select(implode(' UNION ALL ', $selects), $bindings))
            ->filter(fn ($row) => (int) $row->total > 0)
            ->groupBy(fn ($row) => trim($row->referencing_table))
            ->map(fn ($rows, $table) => $this->describe(
                $table,
                $rows->map(fn ($row) => trim($row->referencing_column))->sort()->values()->all(),
                $rows->sum(fn ($row) => (int) $row->total)
            ))
            ->values()
            ->all();

        // Actionable first. A mapped area is somewhere the admin can actually go and
        // reassign rows; a pivot table with a bigger count is not something they can
        // do anything about, so it must not push Customers out of the message.
        usort($blockers, fn ($a, $b) => [$b['location'] !== null, $b['count'], $a['label']]
            <=> [$a['location'] !== null, $a['count'], $b['label']]);

        return $blockers;
    }

    /**
     * Identifiers come from the schema, never from a request, but they are being
     * embedded as string literals so they are escaped anyway.
     */
    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * The sentence the admin sees instead of the SQLSTATE page.
     *
     * @param  array<int, array{table: string, column: string, count: int, label: string, location: ?string}>  $blockers
     */
    public function message(User $user, array $blockers): string
    {
        // The message lands in a toast, and an account can easily be referenced from
        // a dozen places. Name the biggest few precisely and count the rest — a
        // paragraph of seventy clauses tells the reader nothing.
        $named = array_slice($blockers, 0, self::NAMED_IN_MESSAGE);
        $rest = count($blockers) - count($named);

        $parts = array_map(function (array $blocker) {
            $where = $blocker['location'] ? " — see {$blocker['location']}" : '';

            return "{$blocker['count']} in {$blocker['label']}{$where}";
        }, $named);

        $summary = implode('; ', $parts);

        if ($rest > 0) {
            $summary .= sprintf(', and %d other %s', $rest, $rest === 1 ? 'area' : 'areas');
        }

        return sprintf(
            '%s cannot be deleted yet because their records are still in use: %s. Reassign or remove those first, then delete the account.',
            $user->name ?: 'This user',
            $summary
        );
    }

    /**
     * Last resort: the database refused the DELETE over a constraint the scan did
     * not cover. The driver message names the table and column, so say that rather
     * than showing the raw exception.
     */
    public function messageFromException(User $user, QueryException $e): string
    {
        $raw = $e->getMessage();

        // SQL Server: ... table "dbo.customers", column 'created_by'.
        preg_match('/table "(?:[^".]+\.)?([^"]+)", column \'([^\']+)\'/i', $raw, $matches);

        if (count($matches) === 3) {
            $blocker = $this->describe($matches[1], $matches[2], 0);
            $where = $blocker['location'] ? " — see {$blocker['location']}" : '';

            return sprintf(
                '%s cannot be deleted because records in %s%s still refer to this account. Reassign or remove those first, then delete the account.',
                $user->name ?: 'This user',
                $blocker['label'],
                $where
            );
        }

        return sprintf(
            '%s cannot be deleted because other records still refer to this account. Reassign or remove them first, then delete the account.',
            $user->name ?: 'This user'
        );
    }

    /**
     * @param  string|array<int, string>  $columns
     * @return array{table: string, columns: array<int, string>, count: int, label: string, location: ?string}
     */
    private function describe(string $table, string|array $columns, int $count): array
    {
        [$label, $location] = self::LOCATIONS[$table] ?? [Str::headline($table), null];

        return [
            'table' => $table,
            'columns' => (array) $columns,
            'count' => $count,
            'label' => $label,
            'location' => $location,
        ];
    }

    /**
     * Every column in the schema that foreign-keys to `users`.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function referencingColumns(): array
    {
        $connection = Schema::getConnection();

        // One metadata round trip on SQL Server. The generic path below asks every
        // table for its foreign keys, which is fine against SQLite in tests but
        // would be a few hundred round trips over the Azure link.
        if ($connection->getDriverName() === 'sqlsrv') {
            return collect($connection->select(<<<'SQL'
                SELECT OBJECT_NAME(fk.parent_object_id) AS referencing_table,
                       COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS referencing_column
                FROM sys.foreign_keys fk
                INNER JOIN sys.foreign_key_columns fkc
                    ON fkc.constraint_object_id = fk.object_id
                WHERE fk.referenced_object_id = OBJECT_ID('users')
            SQL))
                ->map(fn ($row) => [$row->referencing_table, $row->referencing_column])
                ->all();
        }

        $columns = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'] ?? null;

            if (! $name) {
                continue;
            }

            foreach (Schema::getForeignKeys($name) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'users') {
                    continue;
                }

                foreach ($foreignKey['columns'] ?? [] as $column) {
                    $columns[] = [$name, $column];
                }
            }
        }

        return $columns;
    }
}
