<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ReferenceOption;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

class SeedActivityTemplateE2e extends Command
{
    protected $signature = 'activity-templates:e2e-seed';
    protected $description = 'Seed an isolated activity-template browser-test database';

    public function handle(): int
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite') {
            $this->error('Refusing to seed outside an isolated SQLite testing environment.');
            return self::FAILURE;
        }

        $companies = collect([
            ['TGI', 'Entity'], ['GSI', 'Entity'], ['NCF', 'Entity'], ['DBS', 'Entity'],
            ['NONOS', 'Brand'], ['CBTL', 'Brand'], ['DSY', 'Brand'],
        ])->mapWithKeys(function ($row) {
            $company = Company::updateOrCreate(['code' => $row[0]], [
                'name' => $row[0], 'type' => $row[1], 'is_active' => true,
            ]);
            return [$row[0] => $company];
        });

        foreach ([['TGI','NONOS'], ['GSI','NONOS'], ['TGI','DSY'], ['TGI','CBTL'], ['NCF','CBTL']] as [$entity, $brand]) {
            $companies[$entity]->brands()->syncWithoutDetaching([$companies[$brand]->id]);
        }

        foreach ([
            'Full Service Group: Customer Brand', 'Corporate Group: Servicing Brand',
        ] as $order => $value) {
            ReferenceOption::updateOrCreate(['type' => 'project_type', 'value' => $value], [
                'label' => $value, 'sort_order' => $order + 1,
            ]);
        }
        foreach (['Regular', 'Kitchen', 'Both'] as $order => $value) {
            ReferenceOption::updateOrCreate(['type' => 'store_class', 'value' => $value], [
                'label' => $value, 'sort_order' => $order + 1,
            ]);
        }

        $user = User::factory()->create([
            'name' => 'E2E Activity Template Admin',
            'email' => 'activity-template-e2e@example.test',
            'password' => bcrypt('ActivityTemplateE2E!2026'),
            'company_id' => $companies['TGI']->id,
            'is_active' => true,
        ]);
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            $user->givePermissionTo(Permission::findOrCreate("activity_templates.{$action}", 'web'));
        }

        $this->line(json_encode(['email' => $user->email, 'password' => 'ActivityTemplateE2E!2026']));
        return self::SUCCESS;
    }
}
