<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['clusters', 'categories', 'sub_categories', 'items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Existing rows remain untagged for deliberate assignment by their owner.
                $table->foreignId('department_id')->nullable()->constrained()->onDelete('no action');
                $table->index(['company_id', 'department_id']);
            });
        }

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropUnique('clusters_code_unique');
            $table->dropUnique('clusters_name_unique');
        });
        // Only tagged catalogs are constrained; legacy duplicates remain repairable.
        // Both deployment SQL Server and test SQLite support filtered indexes.
        foreach (['clusters' => ['name', 'code'], 'categories' => ['name'], 'sub_categories' => ['name']] as $name => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE UNIQUE INDEX {$name}_department_{$column}_unique ON {$name} (company_id, department_id, {$column}) WHERE department_id IS NOT NULL");
            }
        }
        DB::statement('CREATE UNIQUE INDEX items_department_classification_unique ON items (company_id, department_id, category_id, sub_category_id, concern_type, name) WHERE department_id IS NOT NULL');

        foreach (['store', 'vendor'] as $reference) {
            Schema::create('department_'.$reference, function (Blueprint $table) use ($reference) {
                $table->id();
                $table->foreignId('department_id')->constrained()->onDelete('no action');
                $table->foreignId($reference.'_id')->constrained()->onDelete('cascade');
                $table->boolean('is_enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['department_id', $reference.'_id']);
            });
        }
    }

    public function down(): void
    {
        // Keep data on rollback: names may now legitimately repeat across departments.
        // Reversing this schema requires a reviewed consolidation, not automatic deletion.
        throw new RuntimeException('Department reference data must be consolidated before reverting this migration.');
    }
};
