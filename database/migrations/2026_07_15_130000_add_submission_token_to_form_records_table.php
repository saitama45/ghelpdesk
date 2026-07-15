<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->string('submission_token', 36)->nullable()->after('request_type_id');
        });

        // SQL Server permits only one NULL in a normal unique index. A filtered
        // index keeps legacy records nullable while enforcing submitted tokens.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement(
                'CREATE UNIQUE INDEX form_records_submission_token_unique
                ON form_records (submission_token)
                WHERE submission_token IS NOT NULL'
            );

            return;
        }

        Schema::table('form_records', function (Blueprint $table) {
            $table->unique('submission_token', 'form_records_submission_token_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX form_records_submission_token_unique ON form_records');
        } else {
            Schema::table('form_records', function (Blueprint $table) {
                $table->dropUnique('form_records_submission_token_unique');
            });
        }

        Schema::table('form_records', function (Blueprint $table) {
            $table->dropColumn('submission_token');
        });
    }
};
