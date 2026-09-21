<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DR image on a receiving: uploaded from the edit modal, stamped on the whole group,
 * kept on the private disk and served only to users who can view receivings.
 */
class StockReceivingDrImageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var \Illuminate\Support\Collection<int, StockReceiving> */
    private $rows;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['stock_receivings.view', 'stock_receivings.edit'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['stock_receivings.view', 'stock_receivings.edit']);

        $category = Category::create(['name' => 'Laptops', 'is_active' => true]);
        $asset = Asset::create(['item_code' => 'SKU1', 'category_id' => $category->id, 'type' => 'Fixed', 'is_active' => true]);

        $this->rows = collect(['BC-1', 'BC-2'])->map(fn ($barcode) => StockReceiving::create([
            'stock_transfer_id' => StockTransfer::create([
                'transfer_date' => '2026-09-21', 'transfer_no' => 'TRF-TEST', 'origin_location' => 'WH',
                'destination_location' => 'ST1', 'status' => 'Posted', 'asset_id' => $asset->id,
                'quantity' => 1, 'barcode' => $barcode, 'qrcode' => $barcode, 'asset_type' => 'New',
            ])->id,
            'receiving_no' => 'RCV-TEST',
            'receiving_date' => '2026-09-21',
            'origin_location' => 'WH',
            'destination_location' => 'ST1',
            'asset_id' => $asset->id,
            'barcode' => $barcode,
            'qrcode' => $barcode,
            'asset_type' => 'New',
            'transferred_quantity' => 1,
            'received_quantity' => 1,
            'condition' => 'Good',
            'status' => 'For Receiving',
        ]));
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            '_method' => 'put',
            'remarks' => 'ok',
            'asset_transfers' => [[
                'asset_id' => $this->rows[0]->asset_id,
                'entries' => $this->rows->map(fn ($row) => [
                    'id' => $row->id, 'received_quantity' => 1, 'condition' => 'Good', 'damage_notes' => '',
                ])->all(),
            ]],
        ], $extra);
    }

    public function test_upload_stamps_every_row_and_is_served_to_viewers_only(): void
    {
        $this->actingAs($this->user)
            ->post("/stock-receivings/{$this->rows[0]->id}", $this->payload([
                'dr_image' => UploadedFile::fake()->image('dr.jpg', 1600, 1200),
            ]))
            ->assertSessionHasNoErrors();

        $paths = StockReceiving::whereIn('id', $this->rows->pluck('id'))->pluck('dr_image_path')->unique();
        $this->assertCount(1, $paths);
        $this->assertStringStartsWith('stock-receiving-dr/', $paths->first());
        Storage::disk('local')->assertExists($paths->first());

        $this->actingAs($this->user)->get("/stock-receivings/{$this->rows[1]->id}/dr-image")->assertOk();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get("/stock-receivings/{$this->rows[1]->id}/dr-image")->assertForbidden();
    }

    public function test_saving_without_a_new_file_keeps_the_image_and_replacing_swaps_it(): void
    {
        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload([
            'dr_image' => UploadedFile::fake()->image('first.jpg'),
        ]));
        $first = $this->rows[0]->fresh()->dr_image_path;

        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertSame($first, $this->rows[1]->fresh()->dr_image_path);

        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload([
            'dr_image' => UploadedFile::fake()->image('second.jpg'),
        ]));
        $second = $this->rows[1]->fresh()->dr_image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload(['remove_dr_image' => '1']));
        $this->assertNull($this->rows[0]->fresh()->dr_image_path);
        $this->actingAs($this->user)->get("/stock-receivings/{$this->rows[0]->id}/dr-image")->assertNotFound();
    }

    public function test_oversized_or_non_image_files_are_rejected(): void
    {
        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload([
            'dr_image' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ]))->assertSessionHasErrors('dr_image');

        $this->actingAs($this->user)->post("/stock-receivings/{$this->rows[0]->id}", $this->payload([
            'dr_image' => UploadedFile::fake()->create('dr.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('dr_image');

        $this->assertNull($this->rows[0]->fresh()->dr_image_path);
    }
}
