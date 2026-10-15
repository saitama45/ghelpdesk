<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Stock In import template names items by their name (description) from a dropdown instead of
 * by item code. Two items sharing a name get their code in brackets so every choice is unique.
 */
class StockInImportItemNameTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $pos;

    private Asset $tumblerA;

    private Asset $tumblerB;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('stock_ins.create', 'web');
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('stock_ins.create');

        $category = Category::create(['name' => 'Equipment', 'is_active' => true]);
        $make = fn (string $code, string $description) => Asset::create([
            'item_code' => $code, 'description' => $description, 'category_id' => $category->id,
            'type' => 'Fixed', 'cost' => 100, 'is_active' => true,
        ]);
        $this->pos = $make('SKU-P1', 'FLYTECH POS617');
        $this->tumblerA = $make('SKU-T1', 'CBTL Tumbler');
        $this->tumblerB = $make('SKU-T2', 'CBTL Tumbler');
    }

    public function test_template_offers_item_names_in_the_item_column_dropdown(): void
    {
        $response = $this->actingAs($this->user)->get(route('stock-ins.template'))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        @unlink($path);

        $sheet = $book->getSheetByName('Import Template');
        $lists = $book->getSheetByName('Lists');

        $this->assertSame('item_name', $sheet->getCell('G1')->getValue());
        $this->assertSame(
            ['CBTL Tumbler (SKU-T1)', 'CBTL Tumbler (SKU-T2)', 'FLYTECH POS617'],
            [$lists->getCell('A2')->getValue(), $lists->getCell('A3')->getValue(), $lists->getCell('A4')->getValue()]
        );
        $this->assertSame('Lists!$A$2:$A$4', $sheet->getCell('G2')->getDataValidation()->getFormula1());
        $this->assertSame('CBTL Tumbler (SKU-T1)', $sheet->getCell('G2')->getValue());
    }

    public function test_import_resolves_items_by_name_and_refuses_an_ambiguous_shared_name(): void
    {
        $head = 'receive_date,dr_no,dr_date,vendor,origin_location,received_by,item_name,serial_no,barcode,qrcode,warranty_months,eol_months,cost,price,destination_location';
        $row = fn (string $item, string $code) => "2026-10-15,DR-5,2026-10-15,ACME,,Tester,{$item},,{$code},QR,12,60,100,0,WH";
        $csv = implode("\n", [
            $head,
            $row('flytech pos617', 'N1'),           // name, any case
            $row('CBTL Tumbler (SKU-T2)', 'N2'),    // shared name with its code, as the dropdown shows it
            $row('SKU-T1', 'N3'),                   // a typed item code still works
            $row('CBTL Tumbler', 'N4'),             // shared name alone: which tumbler? refused
            $row('No Such Item', 'N5'),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('stock-ins.import'), ['file' => UploadedFile::fake()->createWithContent('in.csv', $csv)])
            ->assertOk()
            ->assertJson(['imported' => 3])
            ->assertJsonCount(2, 'errors');

        $this->assertStringContainsString("Row 5: item 'CBTL Tumbler' was not found", $response->json('errors.0'));
        $this->assertSame($this->pos->id, (int) StockIn::where('barcode', 'N1')->value('asset_id'));
        $this->assertSame($this->tumblerB->id, (int) StockIn::where('barcode', 'N2')->value('asset_id'));
        $this->assertSame($this->tumblerA->id, (int) StockIn::where('barcode', 'N3')->value('asset_id'));
        $this->assertFalse(StockIn::whereIn('barcode', ['N4', 'N5'])->exists());
    }
}
