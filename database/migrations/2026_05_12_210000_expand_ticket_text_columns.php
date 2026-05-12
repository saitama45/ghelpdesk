<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE tickets MODIFY description LONGTEXT NULL');
            DB::statement('ALTER TABLE ticket_comments MODIFY comment_text LONGTEXT NOT NULL');

            if (Schema::hasColumn('ticket_comments', 'action_taken')) {
                DB::statement('ALTER TABLE ticket_comments MODIFY action_taken LONGTEXT NULL');
            }

            if (Schema::hasColumn('ticket_comments', 'root_cause_analysis')) {
                DB::statement('ALTER TABLE ticket_comments MODIFY root_cause_analysis LONGTEXT NULL');
            }

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE tickets ALTER COLUMN description NVARCHAR(MAX) NULL');
            DB::statement('ALTER TABLE ticket_comments ALTER COLUMN comment_text NVARCHAR(MAX) NOT NULL');

            if (Schema::hasColumn('ticket_comments', 'action_taken')) {
                DB::statement('ALTER TABLE ticket_comments ALTER COLUMN action_taken NVARCHAR(MAX) NULL');
            }

            if (Schema::hasColumn('ticket_comments', 'root_cause_analysis')) {
                DB::statement('ALTER TABLE ticket_comments ALTER COLUMN root_cause_analysis NVARCHAR(MAX) NULL');
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE tickets MODIFY description TEXT NULL');
            DB::statement('ALTER TABLE ticket_comments MODIFY comment_text TEXT NOT NULL');

            if (Schema::hasColumn('ticket_comments', 'action_taken')) {
                DB::statement('ALTER TABLE ticket_comments MODIFY action_taken TEXT NULL');
            }

            if (Schema::hasColumn('ticket_comments', 'root_cause_analysis')) {
                DB::statement('ALTER TABLE ticket_comments MODIFY root_cause_analysis TEXT NULL');
            }
        }
    }
};
