<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The ticket status catalogue (References → Ticket Statuses): the seven system
 * statuses, renamable, plus custom ones that each behave like a system status.
 *
 * tickets.status carried a CHECK constraint listing the seven keys, which would
 * reject every custom status. It is DISABLED (NOCHECK), not dropped: no data or
 * schema object is removed, and statuses are validated against this table by
 * the application instead. `ALTER TABLE tickets WITH CHECK CHECK CONSTRAINT
 * CK_Tickets_Status` re-enables it once no custom status is in use.
 */
return new class extends Migration
{
    private const SYSTEM = [
        ['open', 'Open', 'blue'],
        ['for_schedule', 'For Schedule', 'teal'],
        ['in_progress', 'In Progress', 'violet'],
        ['resolved', 'Resolved', 'green'],
        ['closed', 'Closed', 'slate'],
        ['waiting_service_provider', 'Waiting for Service Provider', 'orange'],
        ['waiting_client_feedback', "Waiting for Client's Feedback", 'sky'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ticket_statuses')) {
            Schema::create('ticket_statuses', function (Blueprint $table) {
                $table->id();
                // Stored on tickets.status; fixed once created — renaming changes the label only.
                $table->string('key', 50)->unique();
                $table->string('label', 100);
                $table->string('color', 20)->default('slate');
                // Custom statuses only: the system status whose SLA/queue/report behaviour they share.
                $table->string('behaves_like', 50)->nullable();
                $table->boolean('is_system')->default(false);
                $table->integer('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::SYSTEM as $i => [$key, $label, $color]) {
            if (! DB::table('ticket_statuses')->where('key', $key)->exists()) {
                DB::table('ticket_statuses')->insert([
                    'key' => $key, 'label' => $label, 'color' => $color, 'is_system' => true,
                    'sort_order' => ($i + 1) * 10, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF OBJECT_ID('CK_Tickets_Status', 'C') IS NOT NULL ALTER TABLE tickets NOCHECK CONSTRAINT CK_Tickets_Status");
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Permission::firstOrCreate(['name' => 'ticket_statuses.create', 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            foreach (['Admin', 'Solutions Admin', 'Dev'] as $roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role && ! $role->hasPermissionTo('ticket_statuses.create')) {
                    $role->givePermissionTo('ticket_statuses.create');
                }
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Cache::forever('permissions_version', now()->timestamp);
        }
    }

    public function down(): void
    {
        // Keep the catalogue: tickets may already carry custom statuses.
    }
};
