<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** /stores Export: the same filters and entity scope as the list, gated by stores.view. */
class StoreExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'type' => 'Entity', 'is_active' => true]);
        $other = Company::create(['name' => 'GSI', 'code' => 'GSI', 'type' => 'Entity', 'is_active' => true]);
        $store = fn (string $code, string $name, string $class, int $companyId) => Store::create([
            'code' => $code, 'name' => $name, 'sector' => 1, 'area' => 'A', 'brand' => 'B',
            'class' => $class, 'is_active' => true, 'company_id' => $companyId,
        ]);
        $store('0012', 'Makati Kitchen', 'Kitchen', $this->tgi->id);
        $store('0013', 'Ortigas Regular', 'Regular', $this->tgi->id);
        $store('0099', 'Other Entity Store', 'Kitchen', $other->id);
    }

    private function user(array $permissions): User
    {
        $role = Role::create(['name' => 'R'.count($permissions), 'guard_name' => 'web']);
        $role->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        $role->companies()->sync([$this->tgi->id]);
        $user = User::factory()->create(['company_id' => $this->tgi->id]);
        $user->assignRole($role);

        return $user;
    }

    private function rows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'stores').'.xlsx';
        file_put_contents($path, $content);
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        return $rows;
    }

    public function test_export_matches_the_filtered_list_of_the_active_entity(): void
    {
        $response = $this->actingAs($this->user(['stores.view']))
            ->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->get(route('stores.export', ['class' => 'Kitchen']));

        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response->streamedContent());

        $this->assertSame(['Code', 'Name', 'Entity'], array_slice($rows[0], 0, 3));
        $this->assertCount(2, $rows); // header + the one TGI kitchen
        $this->assertSame(['0012', 'Makati Kitchen', 'TGI'], array_slice($rows[1], 0, 3));
    }

    public function test_a_store_can_be_created_for_an_entity_with_no_clusters(): void
    {
        $this->actingAs($this->user(['stores.view', 'stores.create']))
            ->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->post(route('stores.store'), ['code' => 'NEW1', 'name' => 'No Cluster Store', 'sector' => 1,
                'area' => 'A', 'company_id' => $this->tgi->id, 'cluster_ids' => [], 'is_active' => true])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('stores', ['code' => 'NEW1', 'company_id' => $this->tgi->id]);
    }

    public function test_import_accepts_capitalized_headers_and_blank_coordinates(): void
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Code', 'Name', 'Email', 'Sector', 'Area', 'Brand', 'Class', 'Cluster', 'Latitude', 'Longitude', 'Radius (m)', 'Is Active', 'Users'],
            ['IMP1', 'Imported Store', '', '1', 'A', 'B', 'Regular', '', '', '', '', '', ''],
            ['IMP2', 'DS Store', '', '2', 'A', 'B', 'Department Store (DS)', '', '', '', '', '1', ''],
        ], null, 'A1', true);
        // is_active filled down far past the data, as Excel's fill handle does.
        for ($r = 4; $r <= 3000; $r++) {
            $sheet->getActiveSheet()->setCellValue("L{$r}", 1);
        }
        \App\Models\ReferenceOption::firstOrCreate(['type' => 'store_class', 'value' => 'Department Store (DS)'], ['label' => 'Department Store (DS)', 'sort_order' => 5]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);
        $file = new \Illuminate\Http\UploadedFile($path, 'stores-import-template.xlsx', null, null, true);

        $this->actingAs($this->user(['stores.view', 'stores.create']))
            ->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->post(route('stores.import'), ['file' => $file])
            ->assertOk()->assertJson(['imported' => 2, 'errors' => []]);

        $this->assertDatabaseHas('stores', ['code' => 'IMP1', 'latitude' => null, 'longitude' => null,
            'radius_meters' => 150, 'is_active' => true]);
        $this->assertDatabaseHas('stores', ['code' => 'IMP2', 'class' => 'Department Store (DS)']);
    }

    public function test_export_requires_stores_view(): void
    {
        $this->actingAs($this->user(['items.view']))
            ->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->get(route('stores.export'))
            ->assertForbidden();
    }
}
