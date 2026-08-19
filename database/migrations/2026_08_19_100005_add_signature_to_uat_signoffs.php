<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-drawn signatures on UAT sign-offs.
 *
 * The acceptance pack this module replaced had a signature line on every page,
 * and typing a name never fully replaced it — a drawn signature is what people
 * recognise as having signed something. It applies here just as it does to QAT,
 * and it matters more on the client-facing side: external stakeholders sign the
 * acceptance from the tokenised portal, often on a phone with a finger.
 *
 * One nullable column, read by nothing that already exists: every existing
 * sign-off simply has no signature, and the UI falls back to the typed name it
 * has always shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uat_signoffs') && ! Schema::hasColumn('uat_signoffs', 'signature_path')) {
            Schema::table('uat_signoffs', function (Blueprint $table) {
                $table->string('signature_path')->nullable()->after('confirmed_email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('uat_signoffs') && Schema::hasColumn('uat_signoffs', 'signature_path')) {
            Schema::table('uat_signoffs', function (Blueprint $table) {
                $table->dropColumn('signature_path');
            });
        }
    }
};
