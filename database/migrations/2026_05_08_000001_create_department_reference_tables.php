<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('department_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_id', 'name']);
        });

        Schema::create('department_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_section_id')->constrained('department_sections')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_section_id', 'name']);
        });

        Schema::create('department_sub_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_unit_id')->constrained('department_units')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_unit_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'section')) {
                $table->string('section')->nullable();
            }
            if (!Schema::hasColumn('users', 'department_id')) {
                $table->foreignId('department_id')->nullable()->constrained('departments')->noActionOnDelete();
            }
            if (!Schema::hasColumn('users', 'department_section_id')) {
                $table->foreignId('department_section_id')->nullable()->constrained('department_sections')->noActionOnDelete();
            }
            if (!Schema::hasColumn('users', 'department_unit_id')) {
                $table->foreignId('department_unit_id')->nullable()->constrained('department_units')->noActionOnDelete();
            }
            if (!Schema::hasColumn('users', 'department_sub_unit_id')) {
                $table->foreignId('department_sub_unit_id')->nullable()->constrained('department_sub_units')->noActionOnDelete();
            }
        });

        $this->backfillFromExistingUsers();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'department_sub_unit_id')) {
                $table->dropForeign(['department_sub_unit_id']);
            }
            if (Schema::hasColumn('users', 'department_unit_id')) {
                $table->dropForeign(['department_unit_id']);
            }
            if (Schema::hasColumn('users', 'department_section_id')) {
                $table->dropForeign(['department_section_id']);
            }
            if (Schema::hasColumn('users', 'department_id')) {
                $table->dropForeign(['department_id']);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = collect([
                'department_sub_unit_id',
                'department_unit_id',
                'department_section_id',
                'department_id',
                'section',
            ])->filter(fn (string $column) => Schema::hasColumn('users', $column))->values()->all();

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('department_sub_units');
        Schema::dropIfExists('department_units');
        Schema::dropIfExists('department_sections');
        Schema::dropIfExists('departments');
    }

    private function backfillFromExistingUsers(): void
    {
        DB::table('users')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->orderBy('id')
            ->get(['id', 'department', 'unit', 'sub_unit'])
            ->each(function ($user) {
                $departmentName = $this->cleanOrgValue($user->department);
                $unitName = $this->cleanOrgValue($user->unit);
                $subUnitName = $this->cleanOrgValue($user->sub_unit);

                if ($departmentName === '') {
                    return;
                }

                $departmentId = $this->firstOrCreate('departments', [
                    'name' => $departmentName,
                ]);

                $sectionId = $this->firstOrCreate('department_sections', [
                    'department_id' => $departmentId,
                    'name' => 'General',
                ]);

                if ($unitName === '' || $subUnitName === '') {
                    return;
                }

                $unitId = $this->firstOrCreate('department_units', [
                    'department_section_id' => $sectionId,
                    'name' => $unitName,
                ]);

                $subUnitId = $this->firstOrCreate('department_sub_units', [
                    'department_unit_id' => $unitId,
                    'name' => $subUnitName,
                ]);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'department' => $departmentName,
                        'section' => 'General',
                        'unit' => $unitName,
                        'sub_unit' => $subUnitName,
                        'department_id' => $departmentId,
                        'department_section_id' => $sectionId,
                        'department_unit_id' => $unitId,
                        'department_sub_unit_id' => $subUnitId,
                        'updated_at' => now(),
                    ]);
            });
    }

    private function firstOrCreate(string $table, array $attributes): int
    {
        $existing = DB::table($table)->where($attributes)->first(['id']);

        if ($existing) {
            return (int) $existing->id;
        }

        $now = Carbon::now();

        return (int) DB::table($table)->insertGetId([
            ...$attributes,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function cleanOrgValue(?string $value): string
    {
        return trim((string) $value);
    }
};
