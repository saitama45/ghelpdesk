<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Category;
use App\Models\SubCategory;
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

class ItemController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:items.view', only: ['index']),
            new Middleware('can:items.create', only: ['store']),
            new Middleware('can:items.edit', only: ['update']),
            new Middleware('can:items.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Item::with(['category', 'subCategory']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhereHas('category', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  })
                  ->orWhereHas('subCategory', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  });
        }

        $items = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $categories = Category::where('is_active', true)->get();
        $subCategories = SubCategory::where('is_active', true)->get();
        $settings = \App\Models\Setting::all()->pluck('value', 'key');

        return Inertia::render('Items/Index', [
            'items' => $items,
            'categories' => $categories,
            'subCategories' => $subCategories,
            'settings' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'name' => 'required|string|max:255|unique:items,name',
            'description' => 'nullable|string',
            'priority' => 'required|in:Low,Medium,High,Urgent',
            'concern_type' => 'required|in:Incident,Service Request',
            'is_active' => 'boolean',
        ]);

        Item::create($validated);

        return redirect()->back()->with('success', 'Item created successfully');
    }

    public function update(Request $request, Item $item)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'name' => 'required|string|max:255|unique:items,name,' . $item->id,
            'description' => 'nullable|string',
            'priority' => 'required|in:Low,Medium,High,Urgent',
            'concern_type' => 'required|in:Incident,Service Request',
            'is_active' => 'boolean',
        ]);

        $item->update($validated);

        return redirect()->back()->with('success', 'Item updated successfully');
    }

    public function destroy(Item $item)
    {
        $item->delete();
        return redirect()->back()->with('success', 'Item deleted successfully');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $path = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map('trim', array_shift($rows));

        $categoryMap    = Category::pluck('id', 'name')->toArray();
        $subCategoryMap = SubCategory::pluck('id', 'name')->toArray();

        $imported = 0;
        $errors   = [];
        $rowNum   = 1;

        foreach ($rows as $line) {
            $rowNum++;

            // Skip completely empty rows (can appear at end of xlsx)
            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if (count($line) !== count($header)) {
                $errors[] = "Row {$rowNum}: column count mismatch, skipped.";
                continue;
            }

            $data = array_combine($header, array_map(fn($v) => trim((string) $v), $line));

            // Resolve category name → ID
            $categoryId = null;
            if (!empty($data['category'])) {
                if (!isset($categoryMap[$data['category']])) {
                    $errors[] = "Row {$rowNum}: category '{$data['category']}' not found.";
                    continue;
                }
                $categoryId = $categoryMap[$data['category']];
            }

            // Resolve sub_category name → ID
            $subCategoryId = null;
            if (!empty($data['sub_category'])) {
                if (!isset($subCategoryMap[$data['sub_category']])) {
                    $errors[] = "Row {$rowNum}: sub_category '{$data['sub_category']}' not found.";
                    continue;
                }
                $subCategoryId = $subCategoryMap[$data['sub_category']];
            }

            $validator = \Validator::make([
                'name'            => $data['name'] ?? null,
                'description'     => $data['description'] ?? null,
                'priority'        => $data['priority'] ?? null,
                'concern_type'    => $data['concern_type'] ?? 'Incident',
                'category_id'     => $categoryId,
                'sub_category_id' => $subCategoryId,
                'is_active'       => $data['is_active'] ?? '1',
            ], [
                'name'            => 'required|string|max:255|unique:items,name',
                'description'     => 'nullable|string',
                'priority'        => 'required|in:Low,Medium,High,Urgent',
                'concern_type'    => 'required|in:Incident,Service Request',
                'category_id'     => 'nullable|exists:categories,id',
                'sub_category_id' => 'nullable|exists:sub_categories,id',
                'is_active'       => 'nullable|in:0,1',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            Item::create([
                'name'            => $data['name'],
                'description'     => $data['description'] ?: null,
                'priority'        => $data['priority'],
                'concern_type'    => $data['concern_type'] ?? 'Incident',
                'category_id'     => $categoryId,
                'sub_category_id' => $subCategoryId,
                'is_active'       => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);
            $imported++;
        }

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    public function template()
    {
        $categories    = Category::where('is_active', true)->orderBy('name')->get();
        $subCategories = SubCategory::where('is_active', true)->orderBy('name')->get();

        $spreadsheet = new Spreadsheet();

        // ── Hidden Lists sheet ──────────────────────────────────────────
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Categories');
        foreach ($categories as $i => $cat) {
            $listsSheet->setCellValue('A' . ($i + 2), $cat->name);
        }

        $listsSheet->setCellValue('B1', 'Sub-Categories');
        foreach ($subCategories as $i => $sub) {
            $listsSheet->setCellValue('B' . ($i + 2), $sub->name);
        }

        $priorities = ['Low', 'Medium', 'High', 'Urgent'];
        $listsSheet->setCellValue('C1', 'Priority');
        foreach ($priorities as $i => $p) {
            $listsSheet->setCellValue('C' . ($i + 2), $p);
        }

        $concernTypes = ['Incident', 'Service Request'];
        $listsSheet->setCellValue('D1', 'Concern Type');
        foreach ($concernTypes as $i => $ct) {
            $listsSheet->setCellValue('D' . ($i + 2), $ct);
        }

        // ── Import Template sheet ───────────────────────────────────────
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        // Headers
        $headers = ['name', 'description', 'priority', 'concern_type', 'category', 'sub_category', 'is_active'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i); // A–G
            $sheet->setCellValue("{$col}1", $h);
        }

        // Example row
        $sheet->setCellValue('A2', 'Example Item');
        $sheet->setCellValue('B2', 'A short description');
        $sheet->setCellValue('C2', 'Medium');
        $sheet->setCellValue('D2', 'Incident');
        $sheet->setCellValue('E2', $categories->first()?->name ?? '');
        $sheet->setCellValue('F2', $subCategories->first()?->name ?? '');
        $sheet->setCellValue('G2', '1');

        // Header styling
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Auto-size columns A–G
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ── Data Validation ─────────────────────────────────────────────
        $catCount = $categories->count();
        $subCount = $subCategories->count();

        // Priority dropdown — C2:C1001
        $priorityValidation = $sheet->getCell('C2')->getDataValidation();
        $priorityValidation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(false)
            ->setShowDropDown(false)
            ->setFormula1('Lists!$C$2:$C$5')
            ->setSqref('C2:C1001');

        // Concern Type dropdown — D2:D1001
        $concernTypeValidation = $sheet->getCell('D2')->getDataValidation();
        $concernTypeValidation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(false)
            ->setShowDropDown(false)
            ->setFormula1('Lists!$D$2:$D$3')
            ->setSqref('D2:D1001');

        // Category dropdown — E2:E1001
        if ($catCount > 0) {
            $catValidation = $sheet->getCell('E2')->getDataValidation();
            $catValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)
                ->setShowDropDown(false)
                ->setFormula1('Lists!$A$2:$A$' . ($catCount + 1))
                ->setSqref('E2:E1001');
        }

        // Sub-Category dropdown — F2:F1001
        if ($subCount > 0) {
            $subValidation = $sheet->getCell('F2')->getDataValidation();
            $subValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)
                ->setShowDropDown(false)
                ->setFormula1('Lists!$B$2:$B$' . ($subCount + 1))
                ->setSqref('F2:F1001');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $filename = 'items-import-template.xlsx';
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
