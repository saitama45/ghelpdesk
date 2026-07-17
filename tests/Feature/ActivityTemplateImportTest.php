<?php

namespace Tests\Feature;

use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActivityTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_import_and_template_download_require_create_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('activity-templates.template'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('activity-templates.import'), ['file' => $this->workbook([])])
            ->assertForbidden();
    }

    public function test_authorized_user_can_download_excel_template(): void
    {
        $user = $this->userWithCreatePermission();

        $response = $this->actingAs($user)
            ->get(route('activity-templates.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = $this->temporaryPath('.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);

        $this->assertSame($this->headers(), $spreadsheet->getSheetByName('Activity Templates')->rangeToArray('A1:P1')[0]);
        $this->assertNotNull($spreadsheet->getSheetByName('Instructions'));
        $this->assertSame('hidden', $spreadsheet->getSheetByName('Lists')->getSheetState());
    }

    public function test_import_creates_multiple_templates_and_preserves_sub_task_hierarchy(): void
    {
        $user = $this->userWithCreatePermission();
        $rows = [
            $this->row('Opening Template', 'NSO', 'Regular', 'ACT-1', null, 'Prepare site', 'Preparation', 1, 2, 1),
            $this->row('Opening Template', 'NSO', 'Regular', 'SUB-1', 'ACT-1', 'Confirm readiness', 'Preparation', 1, 1, 1),
            $this->row('Closure Template', 'Closure', 'Kitchen', 'ACT-1', null, 'Disconnect utilities', 'Closure', 1, 3, 1),
        ];

        $this->actingAs($user)
            ->post(route('activity-templates.import'), ['file' => $this->workbook($rows)])
            ->assertOk()
            ->assertJson([
                'imported_templates' => 2,
                'skipped_templates' => 0,
                'errors' => [],
            ]);

        $opening = ProjectTemplate::where('name', 'Opening Template')->firstOrFail();
        $parent = $opening->activities()->where('activity', 'Prepare site')->firstOrFail();
        $child = $opening->activities()->where('activity', 'Confirm readiness')->firstOrFail();

        $this->assertNull($parent->parent_activity_template_id);
        $this->assertSame($parent->id, $child->parent_activity_template_id);
        $this->assertDatabaseHas('project_templates', ['name' => 'Closure Template']);
    }

    public function test_import_skips_duplicates_and_invalid_groups_but_imports_valid_groups(): void
    {
        $user = $this->userWithCreatePermission();
        ProjectTemplate::create([
            'name' => 'Existing Template',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $rows = [
            $this->row(' existing template ', 'nso', 'regular', 'ACT-1', null, 'Should not import', 'General', 1, 1, 1),
            $this->row('Invalid Template', 'NSO', 'Regular', 'SUB-1', 'MISSING', 'Orphan task', 'General', 1, 1, 1),
            $this->row('Valid Template', 'NSO', 'Regular', 'ACT-1', null, 'Imported activity', 'General', 1, 1, 1),
        ];

        $response = $this->actingAs($user)
            ->post(route('activity-templates.import'), ['file' => $this->workbook($rows)])
            ->assertOk()
            ->assertJson([
                'imported_templates' => 1,
                'skipped_templates' => 2,
            ]);

        $this->assertCount(2, $response->json('errors'));
        $this->assertSame(2, ProjectTemplate::count());
        $this->assertDatabaseHas('project_templates', ['name' => 'Valid Template']);
        $this->assertDatabaseMissing('project_templates', ['name' => 'Invalid Template']);
        $this->assertDatabaseHas('activity_templates', ['activity' => 'Imported activity']);
        $this->assertDatabaseMissing('activity_templates', ['activity' => 'Should not import']);
    }

    private function userWithCreatePermission(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('activity_templates.create', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$this->headers(), ...$rows]);
        $path = $this->temporaryPath('.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'activity-templates.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function headers(): array
    {
        return [
            'Template Name', 'Project Type', 'Store Class', 'Row Key', 'Parent Row Key',
            'Activity', 'Milestone', 'Milestone Order', 'Asset Item', 'Model Specs',
            'Quantity', 'Responsible', 'Department', 'Sub Unit', 'Duration Days', 'Order',
        ];
    }

    private function row(
        string $template,
        string $projectType,
        string $storeClass,
        string $rowKey,
        ?string $parentRowKey,
        string $activity,
        string $milestone,
        int $milestoneOrder,
        int $duration,
        int $order
    ): array {
        return [
            $template, $projectType, $storeClass, $rowKey, $parentRowKey, $activity,
            $milestone, $milestoneOrder, null, null, 1, null, null, null, $duration, $order,
        ];
    }

    private function temporaryPath(string $suffix): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'activity-template-import-');
        $path = $temporaryFile.$suffix;
        rename($temporaryFile, $path);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
