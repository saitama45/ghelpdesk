<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AssetController extends Controller implements HasMiddleware
{
    // An asset carries exactly one SAP code; a comma/semicolon means a list was entered.
    private const SAP_CODE_MESSAGES = [
        'sap_codes.not_regex' => 'Enter only one SAP code per asset.',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.create', only: ['store', 'import', 'template', 'generateCode']),
            new Middleware('can:assets.edit', only: ['update']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function generateCode()
    {
        return response()->json(['code' => $this->nextItemCode()]);
    }

    private function nextItemCode(): string
    {
        $latestNumber = Asset::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('item_code', 'like', 'SKU%')
            ->pluck('item_code')
            ->map(function (string $itemCode): ?int {
                if (preg_match('/^SKU(\d+)$/', $itemCode, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter()
            ->max();

        return 'SKU' . (($latestNumber ?? 100000) + 1);
    }

    public function index(Request $request)
    {
        $query = Asset::with(['category', 'subCategory']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                  ->orWhere('sap_codes', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('category', fn($cq) => $cq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('subCategory', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        $assets = $query->latest()->paginate($request->input('per_page', 10));
        
        $categories = Category::orderBy('name')->get();
        $subCategories = SubCategory::orderBy('name')->get();
        $brandOptions = Asset::whereNotNull('brand')
            ->pluck('brand')
            ->map(fn (?string $brand) => trim((string) $brand))
            ->filter(fn (string $brand) => $brand !== '')
            ->unique(fn (string $brand) => mb_strtolower($brand))
            ->sortBy(fn (string $brand) => mb_strtolower($brand))
            ->values();
        $modelOptions = Asset::whereNotNull('model')
            ->pluck('model')
            ->map(fn (?string $model) => trim((string) $model))
            ->filter(fn (string $model) => $model !== '')
            ->unique(fn (string $model) => mb_strtolower($model))
            ->sortBy(fn (string $model) => mb_strtolower($model))
            ->values();

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'categories' => $categories,
            'subCategories' => $subCategories,
            'brandOptions' => $brandOptions,
            'modelOptions' => $modelOptions,
            'filters' => $request->only(['search', 'per_page']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sap_codes' => 'nullable|string|max:255|not_regex:/[,;]/',
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'type' => 'required|in:Fixed,Consumables',
            'eol_years' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ], self::SAP_CODE_MESSAGES);

        $validated['item_code'] = $this->nextItemCode();

        Asset::create($validated);

        return redirect()->back()->with('success', 'Asset created successfully');
    }

    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|unique:assets,item_code,' . $asset->id,
            'sap_codes' => 'nullable|string|max:255|not_regex:/[,;]/',
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'type' => 'required|in:Fixed,Consumables',
            'eol_years' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ], self::SAP_CODE_MESSAGES);

        $asset->update($validated);

        return redirect()->back()->with('success', 'Asset updated successfully');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();
        return redirect()->back()->with('success', 'Asset deleted successfully');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map('trim', array_shift($rows) ?? []);
        $categoryMap = Category::orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn (Category $category) => [mb_strtolower(trim($category->name)) => $category->id])
            ->all();
        $subCategoryMap = SubCategory::orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn (SubCategory $subCategory) => [mb_strtolower(trim($subCategory->name)) => $subCategory->id])
            ->all();

        $imported = 0;
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn ($value) => $value !== null && $value !== ''))) {
                continue;
            }

            if (count($line) !== count($header)) {
                $errors[] = "Row {$rowNum}: column count mismatch, skipped.";
                continue;
            }

            $data = array_combine($header, array_map(fn ($value) => trim((string) $value), $line));

            $categoryId = null;
            if (!empty($data['category'])) {
                $categoryKey = mb_strtolower($data['category']);
                if (!isset($categoryMap[$categoryKey])) {
                    $errors[] = "Row {$rowNum}: category '{$data['category']}' not found.";
                    continue;
                }
                $categoryId = $categoryMap[$categoryKey];
            }

            $subCategoryId = null;
            if (!empty($data['sub_category'])) {
                $subCategoryKey = mb_strtolower($data['sub_category']);
                if (!isset($subCategoryMap[$subCategoryKey])) {
                    $errors[] = "Row {$rowNum}: sub-category '{$data['sub_category']}' not found.";
                    continue;
                }
                $subCategoryId = $subCategoryMap[$subCategoryKey];
            }

            $validator = \Validator::make([
                'item_code' => $data['item_code'] ?? null,
                'sap_codes' => ($data['sap_codes'] ?? '') ?: null,
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand' => $data['brand'] ?: null,
                'model' => $data['model'] ?: null,
                'description' => $data['description'] ?: null,
                'cost' => $data['cost'] ?: null,
                'type' => $data['type'] ?? 'Fixed',
                'eol_years' => $data['eol_years'] ?: null,
                'is_active' => $data['is_active'] ?? '1',
            ], [
                'item_code' => 'required|string|max:255|unique:assets,item_code',
                'sap_codes' => 'nullable|string|max:255|not_regex:/[,;]/',
                'category_id' => 'required|exists:categories,id',
                'sub_category_id' => 'nullable|exists:sub_categories,id',
                'brand' => 'nullable|string|max:255',
                'model' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'cost' => 'nullable|numeric|min:0',
                'type' => 'required|in:Fixed,Consumables',
                'eol_years' => 'nullable|integer|min:0',
                'is_active' => 'nullable|in:0,1',
            ], self::SAP_CODE_MESSAGES);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            Asset::create([
                'item_code' => $data['item_code'],
                'sap_codes' => ($data['sap_codes'] ?? '') ?: null,
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand' => $data['brand'] ?: null,
                'model' => $data['model'] ?: null,
                'description' => $data['description'] ?: null,
                'cost' => $data['cost'] !== '' ? $data['cost'] : null,
                'type' => $data['type'] ?? 'Fixed',
                'eol_years' => $data['eol_years'] !== '' ? (int) $data['eol_years'] : null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);

            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    public function template()
    {
        $categories = Category::orderBy('name')->get(['name']);
        $subCategories = SubCategory::orderBy('name')->get(['name']);

        $spreadsheet = new Spreadsheet();

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Categories');
        foreach ($categories as $index => $category) {
            $listsSheet->setCellValue('A' . ($index + 2), $category->name);
        }

        $listsSheet->setCellValue('B1', 'Sub-Categories');
        foreach ($subCategories as $index => $subCategory) {
            $listsSheet->setCellValue('B' . ($index + 2), $subCategory->name);
        }

        $listsSheet->setCellValue('C1', 'Type');
        $listsSheet->setCellValue('C2', 'Fixed');
        $listsSheet->setCellValue('C3', 'Consumables');

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        $headers = ['item_code', 'sap_codes', 'category', 'sub_category', 'brand', 'model', 'description', 'cost', 'type', 'eol_years', 'is_active'];
        foreach ($headers as $index => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $sheet->fromArray([
            [
                'AST-001',
                '100234',
                $categories->get(0)?->name ?? '',
                $subCategories->get(0)?->name ?? '',
                'Dell',
                'Latitude 5440',
                'Sample office laptop',
                '58999.50',
                'Fixed',
                '4',
                '1',
            ],
            [
                'AST-002',
                '200871',
                $categories->get(1)?->name ?? $categories->get(0)?->name ?? '',
                $subCategories->get(1)?->name ?? $subCategories->get(0)?->name ?? '',
                'HP',
                'LaserJet Toner 26A',
                'Sample consumable asset entry',
                '3200.00',
                'Consumables',
                '',
                '1',
            ],
        ], null, 'A2');

        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Text format keeps SAP codes like "0012345" from losing leading zeros in Excel.
        $sheet->getStyle('B2:B1001')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        foreach (range(1, 11) as $colIndex) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if ($categories->isNotEmpty()) {
            $categoryFormula = sprintf('Lists!$A$2:$A$%d', $categories->count() + 1);
            foreach (range(2, 1001) as $row) {
                $categoryValidation = $sheet->getCell("C{$row}")->getDataValidation();
                $categoryValidation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                    ->setAllowBlank(false)
                    ->setShowDropDown(true)
                    ->setShowInputMessage(true)
                    ->setPromptTitle('Select Category')
                    ->setPrompt('Choose an existing category from the dropdown list.')
                    ->setFormula1($categoryFormula);
            }
        }

        if ($subCategories->isNotEmpty()) {
            $subCategoryFormula = sprintf('Lists!$B$2:$B$%d', $subCategories->count() + 1);
            foreach (range(2, 1001) as $row) {
                $subCategoryValidation = $sheet->getCell("D{$row}")->getDataValidation();
                $subCategoryValidation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowInputMessage(true)
                    ->setPromptTitle('Select Sub-Category')
                    ->setPrompt('Choose an existing sub-category from the dropdown list.')
                    ->setFormula1($subCategoryFormula);
            }
        }

        foreach (range(2, 1001) as $row) {
            $typeValidation = $sheet->getCell("I{$row}")->getDataValidation();
            $typeValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(false)
                ->setShowDropDown(true)
                ->setFormula1('Lists!$C$2:$C$3');

            $binaryValidation = $sheet->getCell("K{$row}")->getDataValidation();
            $binaryValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(false)
                ->setShowDropDown(true)
                ->setFormula1('"0,1"');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'assets-import-template.xlsx';
        $httpHeaders = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ];

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $httpHeaders);
    }
}
