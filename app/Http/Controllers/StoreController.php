<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\ReferenceOption;
use App\Models\Store;
use App\Models\StoreBlueprint;
use App\Models\StoreOption;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StoreController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stores.view', only: ['index', 'downloadBlueprint']),
            new Middleware('can:stores.create', only: ['store']),
            new Middleware('can:stores.edit', only: ['update', 'uploadBlueprint', 'destroyBlueprint']),
            new Middleware('can:stores.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Store::with(['users:id,name,email', 'clusters:id,code,name', 'options', 'blueprints'])
            ->withCount(['tickets' => function($q) {
                $q->where('tickets.status', 'open');
            }]);

        if ($request->filled('sector')) {
            $query->where('sector', (int) $request->sector);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
                    ->orWhere('area', 'like', "%{$request->search}%")
                    ->orWhere('brand', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhereHas('clusters', function ($clusterQuery) use ($request) {
                        $clusterQuery->where('name', 'like', "%{$request->search}%")
                            ->orWhere('code', 'like', "%{$request->search}%");
                    })
                    ->orWhereHas('users', function($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        $stores = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $users = User::active()->orderBy('name')->get(['id', 'name']);
        $clusters = Cluster::orderBy('name')->get(['id', 'code', 'name']);
        $settings = Setting::where('group', 'thresholds')->pluck('value', 'key');

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
            'users' => $users,
            'clusters' => $clusters,
            'settings' => $settings,
            'classOptions' => ReferenceOption::ofType('store_class'),
            'hookupOptions' => ReferenceOption::ofType('store_hookup'),
            'systemOptions' => ReferenceOption::ofType('store_system'),
            'telcoOptions' => ReferenceOption::ofType('store_telco'),
            'connectivityOptions' => ReferenceOption::ofType('store_connectivity_type'),
            'remoteAppOptions' => ReferenceOption::ofType('store_remote_app'),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['opening_date' => $request->input('opening_date') ?: null]);

        $validated = $request->validate(
            $this->storeValidationRules('required|string|max:50|unique:stores,code', 'required|string|max:255|unique:stores,name')
        );

        $validated['radius_meters'] = $validated['radius_meters'] ?? 150;
        $validated['class'] = $validated['class'] ?? 'Regular';
        $clusterIds = $this->resolveClusterIds($validated);

        $store = DB::transaction(function () use ($validated, $clusterIds, $request) {
            $store = Store::create($this->scalarStoreAttributes($validated));
            $store->clusters()->sync($clusterIds);

            if ($request->has('user_ids')) {
                $store->users()->sync($request->user_ids);
            }

            $this->syncStoreOptions($store, $request);

            return $store;
        });

        return redirect()->back()
            ->with('success', 'Store created successfully')
            ->with('created_store_id', $store->id);
    }

    public function update(Request $request, Store $store)
    {
        $request->merge(['opening_date' => $request->input('opening_date') ?: null]);

        $validated = $request->validate(
            $this->storeValidationRules(
                'required|string|max:50|unique:stores,code,' . $store->id,
                'required|string|max:255|unique:stores,name,' . $store->id
            )
        );

        $validated['radius_meters'] = $validated['radius_meters'] ?? 150;
        $validated['class'] = $validated['class'] ?? 'Regular';
        $clusterIds = $this->resolveClusterIds($validated);

        DB::transaction(function () use ($store, $validated, $clusterIds, $request) {
            $store->update($this->scalarStoreAttributes($validated));
            $store->clusters()->sync($clusterIds);

            if ($request->has('user_ids')) {
                $store->users()->sync($request->user_ids);
            } else {
                $store->users()->detach();
            }

            $this->syncStoreOptions($store, $request);
        });

        return redirect()->back()->with('success', 'Store updated successfully');
    }

    /**
     * Shared validation rules for store create/update.
     */
    private function storeValidationRules(string $codeRule, string $nameRule): array
    {
        return [
            'code' => $codeRule,
            'name' => $nameRule,
            'sector' => 'required|numeric|min:0|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'class' => 'nullable|string|max:100',
            'cluster' => 'nullable|required_without:cluster_ids|string|max:255',
            'cluster_ids' => 'nullable|required_without:cluster|array',
            'cluster_ids.*' => 'exists:clusters,id',
            'email' => 'nullable|email|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_details' => 'nullable|string|max:255',
            'opening_date' => 'nullable|date',
            'hookup' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'systems' => 'nullable|array',
            'systems.*' => 'string|max:100',
            'telcos' => 'nullable|array',
            'telcos.*' => 'string|max:100',
            'connectivity_types' => 'nullable|array',
            'connectivity_types.*' => 'string|max:100',
            'remote_apps' => 'nullable|array',
            'remote_apps.*.app' => 'required_with:remote_apps|string|max:100',
            'remote_apps.*.id' => 'nullable|string|max:255',
        ];
    }

    /**
     * Pluck only the persistable scalar store columns from validated data.
     */
    private function scalarStoreAttributes(array $validated): array
    {
        return collect($validated)->only([
            'code', 'name', 'sector', 'area', 'brand', 'class', 'email',
            'contact_person', 'contact_details', 'opening_date', 'hookup',
            'latitude', 'longitude', 'radius_meters', 'is_active',
        ])->all();
    }

    /**
     * Replace-all sync of the multi-value store options (systems, telcos,
     * connectivity types, and remote apps) from the request.
     */
    private function syncStoreOptions(Store $store, Request $request): void
    {
        $store->options()->whereIn('type', ['system', 'telco', 'connectivity_type', 'remote_app'])->delete();

        $rows = [];

        foreach (['system' => 'systems', 'telco' => 'telcos', 'connectivity_type' => 'connectivity_types'] as $type => $key) {
            foreach (array_filter((array) $request->input($key, []), fn ($v) => filled($v)) as $value) {
                $rows[] = ['type' => $type, 'value' => (string) $value, 'meta' => null];
            }
        }

        foreach ((array) $request->input('remote_apps', []) as $remote) {
            $app = trim((string) ($remote['app'] ?? ''));
            if ($app === '') {
                continue;
            }
            $rows[] = ['type' => 'remote_app', 'value' => $app, 'meta' => trim((string) ($remote['id'] ?? '')) ?: null];
        }

        if ($rows) {
            $store->options()->createMany($rows);
        }
    }

    private function resolveClusterIds(array $data): array
    {
        if (!empty($data['cluster_ids'])) {
            return array_values($data['cluster_ids']);
        }

        $clusterName = trim((string) ($data['cluster'] ?? ''));
        if ($clusterName === '') {
            return [];
        }

        $cluster = Cluster::firstOrCreate(
            ['name' => $clusterName],
            ['code' => $this->uniqueClusterCode($clusterName)]
        );

        return [$cluster->id];
    }

    private function uniqueClusterCode(string $name): string
    {
        $base = Str::upper(Str::slug(Str::limit($name, 40, ''), '-'));
        $base = $base !== '' ? $base : 'CLUSTER';
        $code = $base;
        $suffix = 1;

        while (Cluster::where('code', $code)->exists()) {
            $code = Str::limit($base, 45, '') . '-' . $suffix;
            $suffix++;
        }

        return $code;
    }

    public function destroy(Store $store)
    {
        $store->delete();
        return redirect()->back()->with('success', 'Store deleted successfully');
    }

    /**
     * Full store profile (with relations) for the at-a-glance details drawer.
     * Available to any authenticated user (not gated) — used from the ticket page.
     */
    public function details(Store $store)
    {
        $store->load([
            'clusters:id,code,name',
            'options',
            'blueprints',
        ]);

        return response()->json([
            'store' => $store,
            'sector_users' => $this->sectorUsers($store->sector),
        ]);
    }

    /**
     * Resolve the user(s) assigned to a store's sector via the "Sector {n}"
     * department node (under Technology and Solutions) and its descendants.
     * Mirrors StoreReportService::sectorAssignments() resolution.
     */
    private function sectorUsers($sector)
    {
        if ($sector === null || $sector === '') {
            return [];
        }

        $nodes = \App\Models\DepartmentNode::where('name', 'Sector ' . (int) $sector)->get();

        if ($nodes->isEmpty()) {
            return [];
        }

        $nodeIds = [];
        foreach ($nodes as $node) {
            $nodeIds[] = $node->id;
            $nodeIds = array_merge($nodeIds, \App\Models\DepartmentNode::getAllDescendantIds($node->id));
        }

        return \App\Models\User::whereIn('department_node_id', array_values(array_unique($nodeIds)))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'position']);
    }

    public function uploadBlueprint(Request $request, Store $store)
    {
        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:25600',
        ]);

        foreach ($request->file('files') as $file) {
            $fileName = now('Asia/Manila')->format('YmdHis') . '_' . Str::uuid() . '_' . $file->getClientOriginalName();
            $path = str_replace('\\', '/', $file->storeAs("store-blueprints/{$store->id}", $fileName, 'public'));

            $store->blueprints()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_storage_path' => $path,
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
                'uploaded_by' => $request->user()->id,
                'uploaded_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Blueprint uploaded.',
            'blueprints' => $store->blueprints()->get(),
        ], 201);
    }

    public function downloadBlueprint(Store $store, StoreBlueprint $blueprint)
    {
        abort_unless($blueprint->store_id === $store->id, 404);

        $fullPath = storage_path('app/public/' . str_replace('/', DIRECTORY_SEPARATOR, $blueprint->file_storage_path));
        abort_unless(is_file($fullPath), 404);

        return response()->download($fullPath, $blueprint->file_name);
    }

    public function destroyBlueprint(Store $store, StoreBlueprint $blueprint)
    {
        abort_unless($blueprint->store_id === $store->id, 404);

        Storage::disk('public')->delete($blueprint->file_storage_path);
        $blueprint->delete();

        return response()->json(['message' => 'Blueprint deleted.']);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map('trim', array_shift($rows));
        $userMap = User::pluck('id', 'email')->toArray();
        $clusters = Cluster::get(['id', 'code', 'name']);
        $clusterLookup = $clusters->flatMap(function (Cluster $cluster) {
            return [
                mb_strtolower(trim($cluster->code)) => $cluster->id,
                mb_strtolower(trim($cluster->name)) => $cluster->id,
            ];
        })->all();

        $imported = 0;
        $errors   = [];
        $rowNum   = 1;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if (count($line) !== count($header)) {
                $errors[] = "Row {$rowNum}: column count mismatch, skipped.";
                continue;
            }

            $data = array_combine($header, array_map(fn($v) => trim((string) $v), $line));
            $clusterValues = isset($data['cluster']) ? explode(';', $data['cluster']) : [];
            $clusterIds = [];
            foreach ($clusterValues as $cv) {
                $cv = mb_strtolower(trim($cv));
                if (isset($clusterLookup[$cv])) {
                    $clusterIds[] = $clusterLookup[$cv];
                }
            }

            $validator = \Validator::make([
                'code'          => $data['code'] ?? null,
                'name'          => $data['name'] ?? null,
                'email'         => $data['email'] ?: null,
                'sector'        => $data['sector'] ?? null,
                'area'          => $data['area'] ?? null,
                'brand'         => $data['brand'] ?? null,
                'class'         => $data['class'] ?? null,
                'cluster'       => $data['cluster'] ?? null,
                'cluster_ids'    => $clusterIds,
                'latitude'      => $data['latitude'] ?: null,
                'longitude'     => $data['longitude'] ?: null,
                'radius_meters' => $data['radius_meters'] ?: null,
                'is_active'     => $data['is_active'] ?? '1',
            ], [
                'code'          => 'required|string|max:50|unique:stores,code',
                'name'          => 'required|string|max:255',
                'email'         => 'nullable|email|max:255',
                'sector'        => 'required|integer|min:0|max:8',
                'area'          => 'required|string|max:255',
                'brand'         => 'required|string|max:255',
                'class'         => 'required|in:Regular,Kitchen,Office',
                'cluster'       => 'required|string|max:255',
                'cluster_ids'    => 'required|array|min:1',
                'latitude'      => 'nullable|numeric|between:-90,90',
                'longitude'     => 'nullable|numeric|between:-180,180',
                'radius_meters' => 'nullable|integer|min:10|max:5000',
                'is_active'     => 'nullable|in:0,1',
            ], [
                'cluster_ids.required' => 'At least one valid cluster is required.',
                'cluster_ids.min' => 'At least one valid cluster is required.',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            $store = Store::create([
                'code'          => $data['code'],
                'name'          => $data['name'],
                'email'         => $data['email'] ?: null,
                'sector'        => (int) $data['sector'],
                'area'          => $data['area'],
                'brand'         => $data['brand'],
                'class'         => $data['class'],
                'latitude'      => $data['latitude'] !== '' ? $data['latitude'] : null,
                'longitude'     => $data['longitude'] !== '' ? $data['longitude'] : null,
                'radius_meters' => !empty($data['radius_meters']) ? (int) $data['radius_meters'] : 150,
                'is_active'     => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);

            if ($clusterIds) {
                $store->clusters()->sync($clusterIds);
            }

            // Resolve user emails → IDs and sync
            $userIds = [];
            if (!empty($data['users'])) {
                foreach (explode(';', $data['users']) as $email) {
                    $email = trim($email);
                    if ($email === '') continue;
                    if (isset($userMap[$email])) {
                        $userIds[] = $userMap[$email];
                    } else {
                        $errors[] = "Row {$rowNum}: user email '{$email}' not found — store imported without this user.";
                    }
                }
            }
            if ($userIds) {
                $store->users()->sync($userIds);
            }

            $imported++;
        }

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    public function template()
    {
        $users = User::active()->orderBy('name')->get(['id', 'name', 'email']);
        $clusters = Cluster::orderBy('name')->get(['code', 'name']);

        $spreadsheet = new Spreadsheet();

        // ── Hidden Lists sheet ──────────────────────────────────────────
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Class');
        $listsSheet->setCellValue('A2', 'Regular');
        $listsSheet->setCellValue('A3', 'Kitchen');
        $listsSheet->setCellValue('A4', 'Office');

        $listsSheet->setCellValue('B1', 'Available Users (email)');
        foreach ($users as $i => $user) {
            $listsSheet->setCellValue('B' . ($i + 2), $user->email);
        }

        $listsSheet->setCellValue('C1', 'Clusters');
        foreach ($clusters as $i => $cluster) {
            $listsSheet->setCellValue('C' . ($i + 2), $cluster->name);
        }

        // ── Import Template sheet ───────────────────────────────────────
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        $headers = [
            'code', 'name', 'email', 'sector', 'area',
            'brand', 'class', 'cluster', 'latitude', 'longitude',
            'radius_meters', 'is_active', 'users',
        ];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }

        // Example row
        $sheet->setCellValue('A2', 'STR-001');
        $sheet->setCellValue('B2', 'Example Store');
        $sheet->setCellValue('C2', 'store@example.com');
        $sheet->setCellValue('D2', '1');
        $sheet->setCellValue('E2', 'Metro Manila');
        $sheet->setCellValue('F2', 'Brand Name');
        $sheet->setCellValue('G2', 'Regular');
        $sheet->setCellValue('H2', $clusters->first()?->name ?? '');
        $sheet->setCellValue('I2', '');
        $sheet->setCellValue('J2', '');
        $sheet->setCellValue('K2', '150');
        $sheet->setCellValue('L2', '1');
        $email1 = $users->get(0)?->email ?? 'user1@example.com';
        $email2 = $users->get(1)?->email ?? 'user2@example.com';
        $sheet->setCellValue('M2', $email1 . ';' . $email2);

        // Header styling
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Auto-size columns A–M
        foreach (range(1, 13) as $colIndex) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Class dropdown — G2:G1001
        $classValidation = $sheet->getCell('G2')->getDataValidation();
        $classValidation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(false)
            ->setShowDropDown(false)
            ->setFormula1('Lists!$A$2:$A$4')
            ->setSqref('G2:G1001');

        if ($clusters->isNotEmpty()) {
            $clusterValidation = $sheet->getCell('H2')->getDataValidation();
            $clusterValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(false)
                ->setShowDropDown(false)
                ->setFormula1(sprintf('Lists!$C$2:$C$%d', $clusters->count() + 1))
                ->setSqref('H2:H1001');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $filename = 'stores-import-template.xlsx';
        $httpHeaders = [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ];

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $httpHeaders);
    }
}
