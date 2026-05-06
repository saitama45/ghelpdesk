<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Repair users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')->nullable();
                $table->foreign('created_by')->references('id')->on('users');
            }

            if (!Schema::hasColumn('users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable();
                $table->foreign('updated_by')->references('id')->on('users');
            }
        });

        // Repair stock_ins table
        if (Schema::hasTable('stock_ins')) {
            Schema::table('stock_ins', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_ins', 'created_by')) {
                    $table->foreignId('created_by')->nullable();
                    $table->foreign('created_by')->references('id')->on('users');
                }

                if (!Schema::hasColumn('stock_ins', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable();
                    $table->foreign('updated_by')->references('id')->on('users');
                }
            });
        }

        // Repair schedules table
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                if (!Schema::hasColumn('schedules', 'created_by')) {
                    $table->foreignId('created_by')->nullable();
                    $table->foreign('created_by')->references('id')->on('users');
                }

                if (!Schema::hasColumn('schedules', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable();
                    $table->foreign('updated_by')->references('id')->on('users');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (Schema::hasColumn('schedules', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('schedules', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            if (Schema::hasColumn('stock_ins', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('stock_ins', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }

            if (Schema::hasColumn('users', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
        });
    }
};
