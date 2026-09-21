<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Asset units of measure: base UOM (what stock is counted in) plus an optional bulk UOM
 * with a pieces-per-bulk conversion. UOM lists come from reference_options.
 */
class AssetUomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['assets.view', 'assets.create', 'assets.edit'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['assets.view', 'assets.create', 'assets.edit']);
        $this->category = Category::create(['name' => 'Supplies', 'is_active' => true]);
    }

    private function asset(array $extra = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'type' => 'Consumables',
            'is_active' => true,
            'base_uom' => 'PC',
        ], $extra);
    }

    public function test_bulk_uom_with_conversion_is_saved(): void
    {
        $this->actingAs($this->user)
            ->post('/assets', $this->asset(['bulk_uom' => 'BOX', 'units_per_bulk' => 12]))
            ->assertSessionHasNoErrors();

        $asset = Asset::firstOrFail();
        $this->assertSame(['PC', 'BOX', 12], [$asset->base_uom, $asset->bulk_uom, $asset->units_per_bulk]);
    }

    public function test_bulk_uom_needs_at_least_two_pieces_and_known_values(): void
    {
        $this->actingAs($this->user)->post('/assets', $this->asset(['bulk_uom' => 'BOX']))
            ->assertSessionHasErrors('units_per_bulk');
        $this->actingAs($this->user)->post('/assets', $this->asset(['bulk_uom' => 'BOX', 'units_per_bulk' => 1]))
            ->assertSessionHasErrors('units_per_bulk');
        $this->actingAs($this->user)->post('/assets', $this->asset(['base_uom' => 'BANANA']))
            ->assertSessionHasErrors('base_uom');

        $this->assertSame(0, Asset::count());
    }

    public function test_clearing_the_bulk_uom_drops_the_conversion(): void
    {
        $this->actingAs($this->user)->post('/assets', $this->asset(['bulk_uom' => 'BOX', 'units_per_bulk' => 12]));
        $asset = Asset::firstOrFail();

        $this->actingAs($this->user)->put("/assets/{$asset->id}", $this->asset([
            'item_code' => $asset->item_code, 'bulk_uom' => '', 'units_per_bulk' => 12,
        ]))->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertNull($asset->bulk_uom);
        $this->assertNull($asset->units_per_bulk);
    }

    public function test_template_and_import_carry_uom_and_old_files_default_to_pc(): void
    {
        $response = $this->actingAs($this->user)->get('/assets/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        @unlink($path);
        $this->assertSame(['base_uom', 'bulk_uom', 'units_per_bulk'], [
            $sheet->getCell('L1')->getValue(), $sheet->getCell('M1')->getValue(), $sheet->getCell('N1')->getValue(),
        ]);
        // Earlier columns did not move.
        $this->assertSame('is_active', $sheet->getCell('K1')->getValue());

        $csv = "item_code,sap_codes,category,sub_category,brand,model,description,cost,type,eol_years,is_active,base_uom,bulk_uom,units_per_bulk\n"
            ."TIS-1,,Supplies,,,,Tissue,10,Consumables,,1,pc,box,24\n"
            ."TIS-2,,Supplies,,,,Bad box,10,Consumables,,1,PC,BOX,\n";
        $this->actingAs($this->user)
            ->post('/assets/import', ['file' => UploadedFile::fake()->createWithContent('uom.csv', $csv)])
            ->assertOk()->assertJson(['imported' => 1])->assertJsonCount(1, 'errors');

        $old = "item_code,category,sub_category,brand,model,description,cost,type,eol_years,is_active\n"
            ."OLD-1,Supplies,,,,Legacy,10,Fixed,4,1\n";
        $this->actingAs($this->user)
            ->post('/assets/import', ['file' => UploadedFile::fake()->createWithContent('old.csv', $old)])
            ->assertOk()->assertJson(['imported' => 1, 'errors' => []]);

        $tissue = Asset::where('item_code', 'TIS-1')->firstOrFail();
        $this->assertSame(['PC', 'BOX', 24], [$tissue->base_uom, $tissue->bulk_uom, $tissue->units_per_bulk]);
        $this->assertSame('PC', Asset::where('item_code', 'OLD-1')->value('base_uom'));
        $this->assertFalse(Asset::where('item_code', 'TIS-2')->exists());
    }
}
