<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the prototype's Facilities Management (FM), Organizational Wellness &
 * Development (OWD), and Leadership Development (LD) departments under TGI so the
 * local/app department set matches the LINK Hub prototype. Idempotent: each is
 * inserted only when a department with that code doesn't already exist under TGI.
 */
return new class extends Migration
{
    private const DEPARTMENTS = [
        ['Facilities Management', 'FM', 'Equipment planning, maintenance, asset condition, safety, and facilities performance.'],
        ['Organizational Wellness & Development', 'OWD', 'OWD initiatives, foundational training, organizational wellness, change programs, and culture.'],
        ['Leadership Development', 'LD', 'Leadership journeys, coaching batches, succession, and manager development.'],
    ];

    public function up(): void
    {
        $tgi = DB::table('companies')->where('code', 'TGI')->value('id');
        if (! $tgi) {
            return; // No TGI entity in this database — nothing to attach to.
        }

        $now = now();
        foreach (self::DEPARTMENTS as [$name, $code, $description]) {
            $exists = DB::table('departments')
                ->where('company_id', $tgi)
                ->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('departments')->insert([
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'is_active' => 1,
                'company_id' => $tgi,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $tgi = DB::table('companies')->where('code', 'TGI')->value('id');
        if (! $tgi) {
            return;
        }

        foreach (self::DEPARTMENTS as [$name, $code, $description]) {
            // Only remove if no users are attached (avoid orphaning placements).
            $deptId = DB::table('departments')->where('company_id', $tgi)->where('code', $code)->value('id');
            if ($deptId && ! DB::table('users')->where('department_id', $deptId)->exists()) {
                DB::table('departments')->where('id', $deptId)->delete();
            }
        }
    }
};
