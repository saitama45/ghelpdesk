<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop foreign keys from users table first
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_sub_unit_id']);
            $table->dropForeign(['department_unit_id']);
            $table->dropForeign(['department_section_id']);
        });

        // 2. Drop the old hierarchy tables
        Schema::dropIfExists('department_sub_units');
        Schema::dropIfExists('department_units');
        Schema::dropIfExists('department_sections');

        // 3. Create the new recursive hierarchy table
        Schema::create('department_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            // Use 'no action' for parent_id to avoid SQL Server multiple cascade path errors
            $table->foreignId('parent_id')->nullable()->constrained('department_nodes')->onDelete('no action');
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'parent_id', 'name'], 'dept_nodes_unique_name');
        });

        // 4. Update users table structure
        Schema::table('users', function (Blueprint $table) {
            // Drop old ID columns
            $table->dropColumn(['department_section_id', 'department_unit_id', 'department_sub_unit_id']);
            
            // Drop old string columns
            $table->dropColumn(['section', 'unit', 'sub_unit']);

            // Add new columns for unlimited hierarchy
            $table->foreignId('department_node_id')->nullable()->constrained('department_nodes')->onDelete('no action');
            $table->string('org_path')->nullable(); // Stores breadcrumb e.g., "Section > Unit > Team"
        });
    }

    public function down(): void
    {
        // Reversing this would require complex data recovery, which isn't feasible for a "fresh start" request.
        // We will just drop the new structure and recreate the old one if needed, but keeping it simple for now.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_node_id']);
            $table->dropColumn(['department_node_id', 'org_path']);
        });

        Schema::dropIfExists('department_nodes');

        // Note: Re-creating old tables here would be incomplete without data backfill, 
        // which the user explicitly wanted to wipe.
    }
};
