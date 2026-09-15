<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Cluster;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Entity switching on /stores, /clusters, /vendors, /categories, /sub-categories:
 * a brand lists its own rows, its tagged entities' rows (read-only) and shared
 * rows; an entity lists only its own and shared rows.
 */
class ReferenceEntityScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $cbtl;

    private Company $gsi;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'type' => 'Entity', 'is_active' => true]);
        $this->cbtl = Company::create(['name' => 'CBTL', 'code' => 'CBTL', 'type' => 'Brand', 'is_active' => true]);
        $this->gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'type' => 'Entity', 'is_active' => true]);
        // CBTL is tagged to TGI on /companies, not to GSI.
        $this->cbtl->entities()->sync([$this->tgi->id]);

        $permissions = [];
        foreach (['stores', 'clusters', 'vendors', 'categories', 'subcategories'] as $module) {
            foreach (['view', 'edit', 'delete'] as $action) {
                $permissions[] = Permission::findOrCreate("{$module}.{$action}", 'web')->name;
            }
        }

        $role = Role::create(['name' => 'Reference Keeper', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $role->companies()->sync([$this->tgi->id, $this->cbtl->id, $this->gsi->id]);

        $this->user = User::factory()->create(['company_id' => $this->tgi->id]);
        $this->user->assignRole($role);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: callable}> */
    public static function pages(): array
    {
        return [
            'stores' => ['stores.index', 'props.stores.data', 'name', 'stores.update', fn (string $name, ?int $companyId) => Store::create([
                'code' => strtoupper(substr(md5($name), 0, 8)), 'name' => $name, 'sector' => 1, 'area' => 'A',
                'brand' => 'B', 'class' => 'Regular', 'is_active' => true, 'company_id' => $companyId,
            ])],
            'clusters' => ['clusters.index', 'props.clusters.data', 'name', 'clusters.update', fn (string $name, ?int $companyId) => self::stamp(
                Cluster::create(['code' => strtoupper(substr(md5($name), 0, 8)), 'name' => $name]), $companyId
            )],
            'vendors' => ['vendors.index', 'props.vendors.data', 'name', 'vendors.update', fn (string $name, ?int $companyId) => self::stamp(
                Vendor::create(['name' => $name, 'is_active' => true]), $companyId
            )],
            'categories' => ['categories.index', 'props.categories.data', 'name', 'categories.update', fn (string $name, ?int $companyId) => self::stamp(
                Category::create(['name' => $name, 'is_active' => true]), $companyId
            )],
            'sub-categories' => ['sub-categories.index', 'props.subcategories.data', 'name', 'sub-categories.update', fn (string $name, ?int $companyId) => self::stamp(
                SubCategory::create(['name' => $name, 'is_active' => true]), $companyId
            )],
        ];
    }

    /** @dataProvider pages */
    public function test_reference_pages_follow_the_entity_switcher(string $indexRoute, string $dataPath, string $nameKey, string $updateRoute, callable $make): void
    {
        $tgiRow = $make('Row TGI', $this->tgi->id);
        $make('Row CBTL', $this->cbtl->id);
        $make('Row GSI', $this->gsi->id);
        $make('Row Shared', null);

        // Brand: own + tagged entity + shared, never an untagged entity.
        $this->assertEqualsCanonicalizing(
            ['Row CBTL', 'Row TGI', 'Row Shared'],
            $this->listed($indexRoute, $dataPath, $nameKey, $this->cbtl)
        );

        // Entity: own + shared only; it does not inherit its brands' rows.
        $this->assertEqualsCanonicalizing(
            ['Row TGI', 'Row Shared'],
            $this->listed($indexRoute, $dataPath, $nameKey, $this->tgi)
        );

        // An inherited row cannot be changed from the brand, even by URL.
        CompanyContext::flushMemo();
        $this->actingAs($this->user)
            ->withSession([CompanyContext::SESSION_KEY => $this->cbtl->id])
            ->put(route($updateRoute, $tgiRow->getKey()), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Row TGI', $tgiRow->fresh()->name);
    }

    private function listed(string $route, string $path, string $nameKey, Company $active): array
    {
        CompanyContext::flushMemo();

        $response = $this->actingAs($this->user)
            ->withSession([CompanyContext::SESSION_KEY => $active->id])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route($route, ['per_page' => 100]));

        $response->assertOk();

        return collect($response->json($path))->pluck($nameKey)->all();
    }

    private static function stamp(Model $model, ?int $companyId): Model
    {
        $model->forceFill(['company_id' => $companyId])->save();

        return $model;
    }
}
