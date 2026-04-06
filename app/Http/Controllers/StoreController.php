<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
            new Middleware('can:stores.view', only: ['index']),
            new Middleware('can:stores.create', only: ['store']),
            new Middleware('can:stores.edit', only: ['update']),
            new Middleware('can:stores.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Store::with(['users:id,name'])
            ->withCount(['tickets' => function($q) {
                $q->where('tickets.status', 'open');
            }]);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%")
                  ->orWhere('area', 'like', "%{$request->search}%")
                  ->orWhere('brand', 'like', "%{$request->search}%")
                  ->orWhere('cluster', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhereHas('users', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  });
        }

        $stores = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $users = User::active()->orderBy('name')->get(['id', 'name']);
        $settings = Setting::where('group', 'thresholds')->pluck('value', 'key');

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
            'users' => $users,
            'settings' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code',
            'name' => 'required|string|max:255|unique:stores,name',
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'class' => 'required|in:Regular,Kitchen',
            'cluster' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $validated['radius_meters'] = $validated['radius_meters'] ?? 150;

        $store = Store::create($validated);

        if ($request->has('user_ids')) {
            $store->users()->sync($request->user_ids);
        }

        return redirect()->back()->with('success', 'Store created successfully');
    }

    public function update(Request $request, Store $store)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code,' . $store->id,
            'name' => 'required|string|max:255|unique:stores,name,' . $store->id,
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'class' => 'required|in:Regular,Kitchen',
            'cluster' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $validated['radius_meters'] = $validated['radius_meters'] ?? 150;

        $store->update($validated);

        if ($request->has('user_ids')) {
            $store->users()->sync($request->user_ids);
        } else {
            $store->users()->detach();
        }

        return redirect()->back()->with('success', 'Store updated successfully');
    }

    public function destroy(Store $store)
    {
        $store->delete();
        return redirect()->back()->with('success', 'Store deleted successfully');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map('trim', array_shift($rows));
        $userMap = User::pluck('id', 'email')->toArray();

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

            $validator = \Validator::make([
                'code'          => $data['code'] ?? null,
                'name'          => $data['name'] ?? null,
                'email'         => $data['email'] ?: null,
                'sector'        => $data['sector'] ?? null,
                'area'          => $data['area'] ?? null,
                'brand'         => $data['brand'] ?? null,
                'class'         => $data['class'] ?? null,
                'cluster'       => $data['cluster'] ?? null,
                'latitude'      => $data['latitude'] ?: null,
                'longitude'     => $data['longitude'] ?: null,
                'radius_meters' => $data['radius_meters'] ?: null,
                'is_active'     => $data['is_active'] ?? '1',
            ], [
                'code'          => 'required|string|max:50|unique:stores,code',
                'name'          => 'required|string|max:255',
                'email'         => 'nullable|email|max:255',
                'sector'        => 'required|integer|min:1|max:8',
                'area'          => 'required|string|max:255',
                'brand'         => 'required|string|max:255',
                'class'         => 'required|in:Regular,Kitchen',
                'cluster'       => 'required|string|max:255',
                'latitude'      => 'nullable|numeric|between:-90,90',
                'longitude'     => 'nullable|numeric|between:-180,180',
                'radius_meters' => 'nullable|integer|min:10|max:5000',
                'is_active'     => 'nullable|in:0,1',
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
                'cluster'       => $data['cluster'],
                'latitude'      => $data['latitude'] !== '' ? $data['latitude'] : null,
                'longitude'     => $data['longitude'] !== '' ? $data['longitude'] : null,
                'radius_meters' => !empty($data['radius_meters']) ? (int) $data['radius_meters'] : 150,
                'is_active'     => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);

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

        $spreadsheet = new Spreadsheet();

        // ── Hidden Lists sheet ──────────────────────────────────────────
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Class');
        $listsSheet->setCellValue('A2', 'Regular');
        $listsSheet->setCellValue('A3', 'Kitchen');

        $listsSheet->setCellValue('B1', 'Available Users (email)');
        foreach ($users as $i => $user) {
            $listsSheet->setCellValue('B' . ($i + 2), $user->email);
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
        $sheet->setCellValue('H2', 'Cluster A');
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
            ->setFormula1('Lists!$A$2:$A$3')
            ->setSqref('G2:G1001');

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
