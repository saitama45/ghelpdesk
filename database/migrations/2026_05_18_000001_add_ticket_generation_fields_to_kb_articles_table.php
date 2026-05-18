<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->foreignId('source_item_id')->nullable()->after('author_id')->constrained('items')->noActionOnDelete();
            $table->foreignUuid('source_ticket_id')->nullable()->after('source_item_id')->constrained('tickets')->noActionOnDelete();
            $table->foreignUuid('source_ticket_comment_id')->nullable()->after('source_ticket_id')->constrained('ticket_comments')->noActionOnDelete();
            $table->string('source_content_fingerprint', 64)->nullable()->after('source_ticket_comment_id');
            $table->boolean('is_ticket_generated')->default(false)->after('source_content_fingerprint');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX kb_articles_source_ticket_unique ON kb_articles (source_ticket_id) WHERE source_ticket_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX kb_articles_source_item_fingerprint_unique ON kb_articles (source_item_id, source_content_fingerprint) WHERE source_item_id IS NOT NULL AND source_content_fingerprint IS NOT NULL');
            return;
        }

        Schema::table('kb_articles', function (Blueprint $table) {
            $table->unique('source_ticket_id', 'kb_articles_source_ticket_unique');
            $table->unique(['source_item_id', 'source_content_fingerprint'], 'kb_articles_source_item_fingerprint_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF EXISTS (SELECT name FROM sys.indexes WHERE name = 'kb_articles_source_item_fingerprint_unique') DROP INDEX kb_articles_source_item_fingerprint_unique ON kb_articles");
            DB::statement("IF EXISTS (SELECT name FROM sys.indexes WHERE name = 'kb_articles_source_ticket_unique') DROP INDEX kb_articles_source_ticket_unique ON kb_articles");
        }

        Schema::table('kb_articles', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlsrv') {
                $table->dropUnique('kb_articles_source_item_fingerprint_unique');
                $table->dropUnique('kb_articles_source_ticket_unique');
            }

            $table->dropConstrainedForeignId('source_item_id');
            $table->dropConstrainedForeignId('source_ticket_id');
            $table->dropConstrainedForeignId('source_ticket_comment_id');
            $table->dropColumn(['source_content_fingerprint', 'is_ticket_generated']);
        });
    }
};
