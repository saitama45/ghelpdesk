<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-department service catalogue (the LINK Hub "Service Exchange"). Each row is
 * a service a department OFFERS to internal customers — what a visitor sees when
 * they open that department's Services hub, independent of their own module
 * permissions (you can request a service you can't administer). Optional
 * route_name deep-links to the module that fulfils the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('eta')->nullable();          // e.g. "4 business hours"
            $table->string('route_name')->nullable();   // deep-link to a fulfilling module
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['department_id', 'is_active']);
        });

        $this->seedDefaults();
    }

    /**
     * Seed a sensible default catalogue per department (idempotent by dept+name).
     * TAS services deep-link to the real modules that fulfil them; others are
     * request-only placeholders the team can refine on /departments.
     */
    private function seedDefaults(): void
    {
        // [Department name matcher, [ [name, description, eta, route_name], ... ]]
        $catalogue = [
            'Technology and Solutions' => [
                ['Report an IT Issue', 'Incident and technical support', '4 business hours', 'tickets.index'],
                ['POS System Request', 'Access, configuration, enhancement, and store support', '1 business day', 'pos-requests.index'],
                ['SAP System Request', 'Roles, authorisation, master data, and enhancements', '2 business days', 'sap-requests.index'],
                ['Work Tools', 'Task board, ticket board, and productivity access', 'Same day', 'task-boards.index'],
                ['Inventory Management', 'Assets, stock movement, and inventory visibility', 'Self-service', 'inventory-workspace.index'],
            ],
            'Finance and Accounting' => [
                ['Payment Request', 'Submit supported payments for review and processing', '2 business days', null],
                ['Reimbursement', 'File an employee or business expense reimbursement', '3 business days', null],
                ['Petty Cash Request', 'Request, approve, release, and liquidate petty cash', '4 business hours', null],
            ],
            'Supply Chain Management' => [
                ['Requisition', 'Request store equipment, peripherals, or materials', '3 business days', null],
                ['Procurement', 'Sourcing and purchase of goods and services', '5 business days', null],
            ],
            'People and Organization' => [
                ['Manpower Request', 'Request headcount or workforce planning support', '5 business days', null],
                ['Employee Services', 'Employee experience, records, and support', '3 business days', null],
            ],
            'Marketing' => [
                ['Campaign Support', 'Campaign launch, materials, and coordination', '3 business days', null],
                ['Store Allocation', 'POSM and campaign item allocation to stores', '2 business days', null],
            ],
            'Facilities Management' => [
                ['Equipment Planning', 'Equipment planning and facilities readiness', '5 business days', null],
                ['Work Order', 'Maintenance and preventive facilities work', '2 business days', null],
            ],
            'Leadership Development' => [
                ['Team Workshop Request', 'Design a leadership or team session', '3 business days', null],
                ['Leadership Assessment', 'Competency, readiness, or development assessment', '3 business days', null],
            ],
        ];

        $now = now();
        foreach ($catalogue as $deptName => $services) {
            $deptId = DB::table('departments')->where('name', $deptName)->value('id');
            if (! $deptId) {
                continue;
            }
            foreach ($services as $i => [$name, $description, $eta, $routeName]) {
                $exists = DB::table('department_services')->where('department_id', $deptId)->where('name', $name)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('department_services')->insert([
                    'department_id' => $deptId,
                    'name' => $name,
                    'description' => $description,
                    'eta' => $eta,
                    'route_name' => $routeName,
                    'sort_order' => $i,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_services');
    }
};
