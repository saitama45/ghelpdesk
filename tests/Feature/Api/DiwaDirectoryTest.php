<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiwaDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-diwa-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.integrations.diwa.key' => self::KEY]);
    }

    public function test_it_rejects_a_missing_or_wrong_key(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->getJson(route('api.integrations.diwa.directory'))->assertStatus(401);
        $this->withHeader('X-Integration-Key', 'nope')->getJson(route('api.integrations.diwa.directory'))->assertStatus(401);

        config(['services.integrations.diwa.key' => '']);
        $this->withHeader('X-Integration-Key', '')->getJson(route('api.integrations.diwa.directory'))->assertStatus(401);
    }

    public function test_it_lists_companies_with_their_brand_links_and_every_store(): void
    {
        $tgi = Company::create(['name' => 'The Table Group Inc.', 'code' => 'TGI', 'type' => 'Entity', 'is_active' => true, 'logo' => 'company-logos/tgi.png']);
        $gsi = Company::create(['name' => 'Gourmet Solutions Inc.', 'code' => 'GSI', 'type' => 'Entity', 'is_active' => true]);
        $nonos = Company::create(['name' => "NONO'S", 'code' => 'NONOS', 'type' => 'Brand', 'is_active' => true]);
        $nonos->entities()->sync([$tgi->id, $gsi->id]);

        Store::create(['code' => 'NN BIC', 'name' => 'Bicutan', 'company_id' => $nonos->id, 'sector' => 1, 'area' => 'South', 'brand' => 'NONOS', 'class' => 'Regular', 'is_active' => true, 'opening_date' => '2024-03-01']);
        Store::create(['code' => 'NN OLD', 'name' => 'Closed Branch', 'company_id' => $nonos->id, 'sector' => 1, 'area' => 'South', 'brand' => 'NONOS', 'class' => 'Regular', 'is_active' => false]);

        $response = $this->withHeader('X-Integration-Key', self::KEY)
            ->getJson(route('api.integrations.diwa.directory'))
            ->assertOk();

        $companies = collect($response->json('companies'))->keyBy('code');

        $this->assertSame('Brand', $companies['NONOS']['type']);
        $this->assertEqualsCanonicalizing([$tgi->id, $gsi->id], $companies['NONOS']['entity_ids']);
        $this->assertSame([$nonos->id], $companies['TGI']['brand_ids']);
        $this->assertStringEndsWith('/serve-storage/company-logos/tgi.png', $companies['TGI']['logo_url']);
        $this->assertNull($companies['GSI']['logo_url']);

        $stores = collect($response->json('stores'))->keyBy('code');

        // A closed store keeps its history attributed to its brand.
        $this->assertSame(['NN BIC', 'NN OLD'], $stores->keys()->all());
        $this->assertFalse($stores['NN OLD']['is_active']);
        $this->assertSame($nonos->id, $stores['NN BIC']['company_id']);
        $this->assertSame('2024-03-01', $stores['NN BIC']['opening_date']);
        $this->assertArrayNotHasKey('cluster_name', $stores['NN BIC']);
    }
}
