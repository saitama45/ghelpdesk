<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Services\HybridSdlcWorkbookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HybridSdlcWorkbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_workbook_contains_and_imports_all_recommended_templates(): void
    {
        $path = storage_path('framework/testing/hybrid-sdlc-'.uniqid().'.xlsx');
        $result = app(HybridSdlcWorkbookService::class)->write($path);

        try {
            $this->assertSame(13, $result['templates']);
            $this->assertSame(771, $result['rows']);

            $book = IOFactory::load($path);
            $this->assertSame(
                ['Activity Templates', 'Template Index', 'Instructions', 'WBS Guide', 'Lists'],
                $book->getSheetNames()
            );
            $this->assertSame(HybridSdlcWorkbookService::HEADERS, $book->getActiveSheet()->rangeToArray('A1:Z1')[0]);
            $this->assertSame('hidden', $book->getSheetByName('Lists')->getSheetState());

            $companies = collect([
                ['TGI', 'Entity'], ['GSI', 'Entity'], ['NCF', 'Entity'], ['DBS', 'Entity'],
                ['NONOS', 'Brand'], ['CBTL', 'Brand'], ['DSY', 'Brand'],
            ])->mapWithKeys(function ($row) {
                $company = Company::create([
                    'name' => $row[0], 'code' => $row[0], 'type' => $row[1], 'is_active' => true,
                ]);
                return [$row[0] => $company];
            });

            foreach ([['TGI','NONOS'], ['GSI','NONOS'], ['TGI','DSY'], ['TGI','CBTL'], ['NCF','CBTL']] as [$entity, $brand]) {
                $companies[$entity]->brands()->attach($companies[$brand]->id);
            }

            Permission::findOrCreate('activity_templates.create', 'web');
            $user = User::factory()->create();
            $user->givePermissionTo('activity_templates.create');

            $response = $this->actingAs($user)->post(route('activity-templates.import'), [
                'file' => new UploadedFile($path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);

            $response->assertOk()->assertJson([
                'imported_templates' => 13, 'skipped_templates' => 0, 'errors' => [],
            ]);
            $this->assertSame(13, ProjectTemplate::count());
            $this->assertDatabaseHas('project_templates', [
                'project_name' => 'DAVID', 'entity_company_id' => $companies['TGI']->id,
                'brand_company_id' => $companies['CBTL']->id,
            ]);
            $this->assertDatabaseHas('activity_templates', [
                'activity' => 'Store Deployment', 'activity_mode' => 'per_store',
            ]);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }
}
