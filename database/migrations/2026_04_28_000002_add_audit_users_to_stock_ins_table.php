<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
        });

        $adminId = DB::table('users')->where('email', 'admin@gmail.com')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if (!$adminId) {
            return;
        }

        DB::table('stock_ins')
            ->whereNull('created_by')
            ->update(['created_by' => $adminId]);

        DB::table('stock_ins')
            ->whereNull('updated_by')
            ->update(['updated_by' => $adminId]);
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
