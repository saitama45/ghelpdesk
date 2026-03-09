<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlsrv') {
            // Fix ticket_comments.created_at
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->timestamp('created_at_new')->nullable();
            });
            DB::statement("UPDATE ticket_comments SET created_at_new = CAST(created_at AS DATETIME2)");
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->dropColumn('created_at');
            });
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->renameColumn('created_at_new', 'created_at');
            });

            // Fix ticket_attachments.uploaded_date
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->timestamp('uploaded_date_new')->nullable();
            });
            DB::statement("UPDATE ticket_attachments SET uploaded_date_new = CAST(uploaded_date AS DATETIME2)");
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->dropColumn('uploaded_date');
            });
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->renameColumn('uploaded_date_new', 'uploaded_date');
            });
        } else {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->timestamp('created_at')->change();
            });
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->timestamp('uploaded_date')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->timestampTz('created_at')->change();
        });
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->timestampTz('uploaded_date')->change();
        });
    }
};
