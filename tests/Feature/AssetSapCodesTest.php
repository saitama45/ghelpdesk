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
 * SAP Codes on assets: manual entry on create/edit, and the import template + import.
 */
class AssetSapCodesTest extends TestCase
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
        $this->category = Category::create(['name' => 'Laptops', 'is_active' => true]);
    }

    public function test_create_and_edit_save_sap_codes(): void
    {
        $this->actingAs($this->user)->post('/assets', [
            'sap_codes' => '100234',
            'category_id' => $this->category->id,
            'type' => 'Fixed',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $asset = Asset::firstOrFail();
        $this->assertSame('100234', $asset->sap_codes);

        $this->actingAs($this->user)->put("/assets/{$asset->id}", [
            'item_code' => $asset->item_code,
            'sap_codes' => '0099887',
            'category_id' => $this->category->id,
            'type' => 'Fixed',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('0099887', $asset->fresh()->sap_codes);
    }

    public function test_multiple_sap_codes_are_rejected(): void
    {
        $this->actingAs($this->user)->post('/assets', [
            'sap_codes' => '100234, 100235',
            'category_id' => $this->category->id,
            'type' => 'Fixed',
            'is_active' => true,
        ])->assertSessionHasErrors('sap_codes');

        $this->assertSame(0, Asset::count());
    }

    public function test_template_has_sap_codes_column_after_item_code(): void
    {
        $response = $this->actingAs($this->user)->get('/assets/template');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        @unlink($path);

        $this->assertSame('item_code', $sheet->getCell('A1')->getValue());
        $this->assertSame('sap_codes', $sheet->getCell('B1')->getValue());
        // Samples show one code per asset, never a list.
        foreach (['B2', 'B3'] as $cell) {
            $this->assertDoesNotMatchRegularExpression('/[,;]/', (string) $sheet->getCell($cell)->getValue());
        }
        $this->assertSame('category', $sheet->getCell('C1')->getValue());
        $this->assertSame('is_active', $sheet->getCell('K1')->getValue());
        // Dropdowns moved with their columns.
        $this->assertSame('list', $sheet->getCell('C2')->getDataValidation()->getType());
        $this->assertSame('"0,1"', $sheet->getCell('K2')->getDataValidation()->getFormula1());
    }

    public function test_import_reads_one_sap_code_rejects_lists_and_accepts_old_template(): void
    {
        $new = "item_code,sap_codes,category,sub_category,brand,model,description,cost,type,eol_years,is_active\n"
            ."AST-100,200871,Laptops,,Dell,Latitude,,100,Fixed,4,1\n"
            ."AST-101,,Laptops,,HP,ProBook,,100,Fixed,4,1\n"
            ."AST-103,\"200871, 200872\",Laptops,,Acer,Swift,,100,Fixed,4,1\n";
        $this->actingAs($this->user)
            ->post('/assets/import', ['file' => UploadedFile::fake()->createWithContent('new.csv', $new)])
            ->assertOk()->assertJson(['imported' => 2])->assertJsonCount(1, 'errors');

        $old = "item_code,category,sub_category,brand,model,description,cost,type,eol_years,is_active\n"
            ."AST-102,Laptops,,Lenovo,ThinkPad,,100,Fixed,4,1\n";
        $this->actingAs($this->user)
            ->post('/assets/import', ['file' => UploadedFile::fake()->createWithContent('old.csv', $old)])
            ->assertOk()->assertJson(['imported' => 1, 'errors' => []]);

        $this->assertSame('200871', Asset::where('item_code', 'AST-100')->value('sap_codes'));
        $this->assertFalse(Asset::where('item_code', 'AST-103')->exists());
        $this->assertNull(Asset::where('item_code', 'AST-101')->value('sap_codes'));
        $this->assertNull(Asset::where('item_code', 'AST-102')->value('sap_codes'));
    }
}
