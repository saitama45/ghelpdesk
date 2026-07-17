<?php

namespace App\Http\Controllers;

use App\Models\ActivityTemplate;
use App\Models\ProjectTemplate;
use App\Models\ReferenceOption;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ActivityTemplateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:activity_templates.view', only: ['index', 'export']),
            new Middleware('can:activity_templates.create', only: ['store', 'template', 'import']),
            new Middleware('can:activity_templates.edit', only: ['update']),
            new Middleware('can:activity_templates.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = ProjectTemplate::with(['activities']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->filled('store_class')) {
            $query->whereIn('store_class', [$request->store_class, 'Both']);
        }

        $templates = $query->orderBy('name')
            ->paginate($request->get('per_page', 15))
            ->withQueryString();

        $subUnits = User::whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->distinct()
            ->pluck('org_path')
            ->sort()
            ->values();

        return Inertia::render('ActivityTemplates/Index', [
            'templates' => $templates,
            'subUnits' => $subUnits,
            'departmentOptions' => $this->departmentOptions(),
            'projectTypes' => ReferenceOption::ofType('project_type'),
            'storeClasses' => ReferenceOption::ofType('store_class'),
            'filters' => $request->only(['search', 'store_class']),
        ]);
    }

    public function template()
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activity Templates');

        $instructions = $spreadsheet->createSheet(1);
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Activity Template Import Instructions'],
            ['1. Keep the column headers unchanged.'],
            ['2. Repeat Template Name, Project Type, and Store Class on every activity row.'],
            ['3. Row Key must be unique within a template. Use Parent Row Key for a sub-task.'],
            ['4. Only one sub-task level is supported; a sub-task cannot be another row\'s parent.'],
            ['5. Existing templates with the same name, project type, and store class are skipped.'],
            ['6. Remove the example rows before importing your own data.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(110);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        [$projectTypes, $storeClasses, $departments, $subUnits] = $this->addImportListSheet($spreadsheet, 2);

        $headers = $this->importHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            ['New Store Opening', $projectTypes->first(), $storeClasses->first(), 'ACT-1', null, 'Prepare site', 'Preparation', 1, null, null, 1, 'Project Team', $departments->first(), $subUnits->first(), 2, 1],
            ['New Store Opening', $projectTypes->first(), $storeClasses->first(), 'SUB-1', 'ACT-1', 'Confirm site readiness', 'Preparation', 1, null, null, 1, 'Project Team', $departments->first(), $subUnits->first(), 1, 1],
        ], null, 'A2');

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A1:{$lastColumn}1000")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        $this->applyImportDropdowns($sheet, $projectTypes, $storeClasses, $departments, $subUnits);

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="activity-templates-import-template.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120']);

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'The uploaded Excel workbook could not be read.',
                'errors' => ['file' => ['The uploaded Excel workbook could not be read.']],
            ], 422);
        }

        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $headerRow = array_shift($rows) ?? [];
        $headerIndexes = [];

        foreach ($headerRow as $index => $header) {
            $headerIndexes[$this->normalizeImportValue($header)] = $index;
        }

        $missingHeaders = collect($this->importHeaders())
            ->reject(fn (string $header) => array_key_exists($this->normalizeImportValue($header), $headerIndexes))
            ->values()
            ->all();

        if ($missingHeaders !== []) {
            return response()->json([
                'message' => 'The workbook is missing required columns.',
                'errors' => ['file' => ['Missing columns: '.implode(', ', $missingHeaders)]],
            ], 422);
        }

        $groups = [];
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;

            if (collect($row)->every(fn ($value) => blank($value))) {
                continue;
            }

            $data = [];
            foreach ($this->importHeaders() as $header) {
                $value = $row[$headerIndexes[$this->normalizeImportValue($header)]] ?? null;
                $data[$header] = is_string($value) ? trim($value) : $value;
            }

            $identityValidator = Validator::make($data, [
                'Template Name' => 'required|string|max:255',
                'Project Type' => 'required|string|max:100',
                'Store Class' => 'required|string|max:100',
            ]);

            if ($identityValidator->fails()) {
                foreach ($identityValidator->errors()->all() as $message) {
                    $errors[] = "Row {$excelRow}: {$message}";
                }
                continue;
            }

            $identity = $this->importIdentity(
                $data['Template Name'],
                $data['Project Type'],
                $data['Store Class']
            );
            $groups[$identity]['name'] = $data['Template Name'];
            $groups[$identity]['project_type'] = $data['Project Type'];
            $groups[$identity]['store_class'] = $data['Store Class'];
            $groups[$identity]['rows'][] = ['excel_row' => $excelRow, 'data' => $data];
        }

        if ($groups === [] && $errors === []) {
            return response()->json([
                'message' => 'The workbook does not contain any activity rows.',
                'errors' => ['file' => ['Add at least one activity row before importing the workbook.']],
            ], 422);
        }

        $existingIdentities = ProjectTemplate::query()
            ->get(['name', 'project_type', 'store_class'])
            ->mapWithKeys(fn (ProjectTemplate $template) => [
                $this->importIdentity($template->name, $template->project_type, $template->store_class) => true,
            ])
            ->all();

        $importedTemplates = 0;
        $skippedTemplates = 0;

        foreach ($groups as $identity => $group) {
            $label = "{$group['name']} ({$group['project_type']} / {$group['store_class']})";

            if (isset($existingIdentities[$identity])) {
                $skippedTemplates++;
                $errors[] = "{$label}: skipped because this template already exists.";
                continue;
            }

            [$activities, $groupErrors] = $this->validateImportActivities($group['rows']);

            if ($groupErrors !== []) {
                $skippedTemplates++;
                foreach ($groupErrors as $message) {
                    $errors[] = "{$label}: {$message}";
                }
                continue;
            }

            try {
                DB::transaction(function () use ($group, $activities) {
                    $projectTemplate = ProjectTemplate::create([
                        'name' => $group['name'],
                        'project_type' => $group['project_type'],
                        'store_class' => $group['store_class'],
                    ]);

                    $this->persistActivities($projectTemplate, $activities);
                });

                $existingIdentities[$identity] = true;
                $importedTemplates++;
            } catch (Throwable $exception) {
                report($exception);
                $skippedTemplates++;
                $errors[] = "{$label}: import failed and no rows were saved.";
            }
        }

        return response()->json([
            'imported_templates' => $importedTemplates,
            'skipped_templates' => $skippedTemplates,
            'errors' => $errors,
        ]);
    }

    public function export(ProjectTemplate $activity_template)
    {
        $activity_template->load('activities');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activity Template');
        [$projectTypes, $storeClasses, $departments, $subUnits] = $this->addImportListSheet($spreadsheet, 1, $activity_template);
        $headers = $this->importHeaders();
        $sheet->fromArray($headers, null, 'A1');

        $rows = $activity_template->activities->map(function (ActivityTemplate $activity) use ($activity_template) {
            return [
                $activity_template->name,
                $activity_template->project_type,
                $activity_template->store_class,
                'ACT-'.$activity->id,
                $activity->parent_activity_template_id ? 'ACT-'.$activity->parent_activity_template_id : null,
                $activity->activity,
                $activity->milestone,
                $activity->milestone_order,
                $activity->asset_item,
                $activity->model_specs,
                $activity->qty,
                $activity->responsible,
                $activity->department,
                $activity->sub_unit,
                $activity->default_duration_days,
                $activity->order,
            ];
        })->all();

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
            $sheet->getStyle('P2:P'.(count($rows) + 1))->getNumberFormat()->setFormatCode('0.0#');
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        $this->applyImportDropdowns($sheet, $projectTypes, $storeClasses, $departments, $subUnits);
        $spreadsheet->setActiveSheetIndex(0);

        $filename = (Str::slug($activity_template->name) ?: 'activity-template').'.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|string|max:100',
            'store_class' => 'required|string|max:100',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.milestone_order' => 'nullable|integer|min:0',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|numeric|min:1',
        ]);

        DB::transaction(function () use ($validated) {
            $projectTemplate = ProjectTemplate::create([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            $this->persistActivities($projectTemplate, $validated['activities']);
        });

        return redirect()->back()->with('success', 'Project template created successfully');
    }

    public function update(Request $request, ProjectTemplate $activity_template)
    {
        // Renamed parameter to match existing route binding if necessary, 
        // but typically Laravel uses the variable name from the route.
        // Assuming the route parameter is {activity_template}
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|string|max:100',
            'store_class' => 'required|string|max:100',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.milestone_order' => 'nullable|integer|min:0',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|numeric|min:1',
        ]);

        DB::transaction(function () use ($validated, $activity_template) {
            $activity_template->update([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            $this->persistActivities($activity_template, $validated['activities']);
        });

        return redirect()->back()->with('success', 'Project template updated successfully');
    }

    public function destroy(ProjectTemplate $activity_template)
    {
        $activity_template->delete();
        return redirect()->back()->with('success', 'Project template deleted successfully');
    }

    private function persistActivities(ProjectTemplate $projectTemplate, array $activities): void
    {
        $activities = collect($activities)
            ->values()
            ->map(function (array $activity, int $index) {
                $activity['client_key'] = filled($activity['client_key'] ?? null)
                    ? (string) $activity['client_key']
                    : 'activity-' . $index;
                $activity['parent_client_key'] = filled($activity['parent_client_key'] ?? null)
                    ? (string) $activity['parent_client_key']
                    : null;

                return $activity;
            });
        $activities = $this->assignMissingMilestoneOrders($activities);

        $this->validateActivityHierarchy($projectTemplate, $activities);

        $submittedIds = $activities->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $existingIds = $projectTemplate->activities()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $removedIds = array_values(array_diff($existingIds, $submittedIds));

        if (!empty($removedIds)) {
            $projectTemplate->activities()
                ->whereIn('id', $removedIds)
                ->whereNotNull('parent_activity_template_id')
                ->delete();

            $projectTemplate->activities()
                ->whereIn('id', $removedIds)
                ->whereNull('parent_activity_template_id')
                ->delete();
        }

        $savedByClientKey = [];

        foreach ($activities->filter(fn ($activity) => empty($activity['parent_client_key']))->sortBy('order') as $activity) {
            $model = $this->saveActivity($projectTemplate, $activity, null);
            $savedByClientKey[$activity['client_key']] = $model;
        }

        foreach ($activities->filter(fn ($activity) => !empty($activity['parent_client_key']))->sortBy('order') as $activity) {
            $parent = $savedByClientKey[$activity['parent_client_key']] ?? null;

            if (!$parent) {
                throw ValidationException::withMessages([
                    'activities' => 'Each sub-task must belong to an activity in the same template.',
                ]);
            }

            $activity['department'] = blank($activity['department'] ?? null) ? $parent->department : $activity['department'];
            $activity['sub_unit'] = blank($activity['sub_unit'] ?? null) ? $parent->sub_unit : $activity['sub_unit'];

            $model = $this->saveActivity($projectTemplate, $activity, $parent->id);
            $savedByClientKey[$activity['client_key']] = $model;
        }
    }

    private function validateActivityHierarchy(ProjectTemplate $projectTemplate, $activities): void
    {
        $existingIds = $projectTemplate->exists
            ? $projectTemplate->activities()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $clientKeys = $activities->pluck('client_key')->all();
        $uniqueClientKeys = array_unique($clientKeys);

        if (count($clientKeys) !== count($uniqueClientKeys)) {
            throw ValidationException::withMessages([
                'activities' => 'Activity rows must have unique client keys.',
            ]);
        }

        $byClientKey = $activities->keyBy('client_key');

        foreach ($activities as $activity) {
            if (!empty($activity['id']) && !in_array((int) $activity['id'], $existingIds, true)) {
                throw ValidationException::withMessages([
                    'activities' => 'One or more activities do not belong to this template.',
                ]);
            }

            $parentClientKey = $activity['parent_client_key'] ?? null;

            if (!$parentClientKey) {
                continue;
            }

            if ($parentClientKey === $activity['client_key'] || !$byClientKey->has($parentClientKey)) {
                throw ValidationException::withMessages([
                    'activities' => 'Each sub-task must belong to an activity in the same template.',
                ]);
            }

            $parent = $byClientKey[$parentClientKey];

            if (!empty($parent['parent_client_key'])) {
                throw ValidationException::withMessages([
                    'activities' => 'Only one sub-task level is supported.',
                ]);
            }
        }
    }

    private function assignMissingMilestoneOrders($activities)
    {
        $ordersByMilestone = [];
        $nextOrder = 1;

        $activities
            ->filter(fn ($activity) => empty($activity['parent_client_key']))
            ->each(function ($activity) use (&$ordersByMilestone, &$nextOrder) {
                $milestone = $activity['milestone'] ?: 'General';

                if (!array_key_exists($milestone, $ordersByMilestone)) {
                    $ordersByMilestone[$milestone] = filled($activity['milestone_order'] ?? null)
                        ? (int) $activity['milestone_order']
                        : $nextOrder;
                    $nextOrder = max($nextOrder, $ordersByMilestone[$milestone] + 1);
                }
            });

        return $activities->map(function (array $activity) use ($ordersByMilestone) {
            $milestone = $activity['milestone'] ?: 'General';
            $activity['milestone_order'] = filled($activity['milestone_order'] ?? null)
                ? (int) $activity['milestone_order']
                : ($ordersByMilestone[$milestone] ?? 1);

            return $activity;
        });
    }

    private function saveActivity(ProjectTemplate $projectTemplate, array $activity, ?int $parentActivityId): ActivityTemplate
    {
        $attributes = [
            'parent_activity_template_id' => $parentActivityId,
            'activity' => $activity['activity'],
            'milestone' => $activity['milestone'] ?? null,
            'milestone_order' => $activity['milestone_order'] ?? null,
            'asset_item' => $activity['asset_item'] ?? null,
            'model_specs' => $activity['model_specs'] ?? null,
            'qty' => $activity['qty'],
            'responsible' => $activity['responsible'] ?? null,
            'department' => blank($activity['department'] ?? null) ? null : $activity['department'],
            'sub_unit' => blank($activity['sub_unit'] ?? null) ? null : $activity['sub_unit'],
            'default_duration_days' => $activity['default_duration_days'],
            'order' => $activity['order'],
        ];

        if (!empty($activity['id'])) {
            $model = $projectTemplate->activities()->whereKey($activity['id'])->firstOrFail();
            $model->update($attributes);

            return $model;
        }

        return $projectTemplate->activities()->create($attributes);
    }

    private function importHeaders(): array
    {
        return [
            'Template Name',
            'Project Type',
            'Store Class',
            'Row Key',
            'Parent Row Key',
            'Activity',
            'Milestone',
            'Milestone Order',
            'Asset Item',
            'Model Specs',
            'Quantity',
            'Responsible',
            'Department',
            'Sub Unit',
            'Duration Days',
            'Order',
        ];
    }

    private function normalizeImportValue(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function importIdentity(mixed $name, mixed $projectType, mixed $storeClass): string
    {
        return implode('|', [
            $this->normalizeImportValue($name),
            $this->normalizeImportValue($projectType),
            $this->normalizeImportValue($storeClass),
        ]);
    }

    private function validateImportActivities(array $rows): array
    {
        $activities = [];
        $errors = [];
        $rowKeys = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $excelRow = $row['excel_row'];
            $activity = [
                'client_key' => $data['Row Key'],
                'parent_client_key' => blank($data['Parent Row Key']) ? null : $data['Parent Row Key'],
                'activity' => $data['Activity'],
                'milestone' => blank($data['Milestone']) ? 'General' : $data['Milestone'],
                'milestone_order' => blank($data['Milestone Order']) ? null : $data['Milestone Order'],
                'asset_item' => blank($data['Asset Item']) ? null : $data['Asset Item'],
                'model_specs' => blank($data['Model Specs']) ? null : $data['Model Specs'],
                'qty' => $data['Quantity'],
                'responsible' => blank($data['Responsible']) ? null : $data['Responsible'],
                'department' => blank($data['Department']) ? null : $data['Department'],
                'sub_unit' => blank($data['Sub Unit']) ? null : $data['Sub Unit'],
                'default_duration_days' => $data['Duration Days'],
                'order' => $data['Order'],
            ];

            $validator = Validator::make($activity, [
                'client_key' => 'required|string|max:255',
                'parent_client_key' => 'nullable|string|max:255',
                'activity' => 'required|string|max:255',
                'milestone' => 'nullable|string|max:255',
                'milestone_order' => 'nullable|integer|min:0',
                'asset_item' => 'nullable|string|max:255',
                'model_specs' => 'nullable|string|max:255',
                'qty' => 'required|integer|min:1',
                'responsible' => 'nullable|string|max:255',
                'department' => 'nullable|string|max:255',
                'sub_unit' => 'nullable|string|max:255',
                'default_duration_days' => 'required|integer|min:1',
                'order' => 'required|numeric|min:1',
            ], [], [
                'client_key' => 'Row Key',
                'parent_client_key' => 'Parent Row Key',
                'activity' => 'Activity',
                'milestone' => 'Milestone',
                'milestone_order' => 'Milestone Order',
                'asset_item' => 'Asset Item',
                'model_specs' => 'Model Specs',
                'qty' => 'Quantity',
                'responsible' => 'Responsible',
                'department' => 'Department',
                'sub_unit' => 'Sub Unit',
                'default_duration_days' => 'Duration Days',
                'order' => 'Order',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "row {$excelRow}: {$message}";
                }
                continue;
            }

            $normalizedKey = $this->normalizeImportValue($activity['client_key']);
            if (isset($rowKeys[$normalizedKey])) {
                $errors[] = "row {$excelRow}: Row Key '{$activity['client_key']}' is duplicated (first used on row {$rowKeys[$normalizedKey]}).";
                continue;
            }

            $rowKeys[$normalizedKey] = $excelRow;
            $activity['client_key'] = $normalizedKey;
            $activity['parent_client_key'] = $activity['parent_client_key'] === null
                ? null
                : $this->normalizeImportValue($activity['parent_client_key']);
            $activities[] = $activity;
        }

        if ($errors !== []) {
            return [$activities, $errors];
        }

        $activitiesByKey = collect($activities)->keyBy('client_key');

        foreach ($activities as $index => $activity) {
            $parentKey = $activity['parent_client_key'];
            if ($parentKey === null) {
                continue;
            }

            if ($parentKey === $activity['client_key']) {
                $errors[] = "row {$rows[$index]['excel_row']}: Parent Row Key cannot reference the same row.";
                continue;
            }

            $parent = $activitiesByKey->get($parentKey);
            if (! $parent) {
                $errors[] = "row {$rows[$index]['excel_row']}: Parent Row Key '{$rows[$index]['data']['Parent Row Key']}' was not found in this template.";
                continue;
            }

            if ($parent['parent_client_key'] !== null) {
                $errors[] = "row {$rows[$index]['excel_row']}: only one sub-task level is supported.";
            }
        }

        return [$activities, $errors];
    }

    private function addImportListSheet(
        Spreadsheet $spreadsheet,
        int $sheetIndex,
        ?ProjectTemplate $projectTemplate = null
    ): array {
        $listsSheet = $spreadsheet->createSheet($sheetIndex);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $projectTypes = collect(ReferenceOption::valuesOfType('project_type'));
        if ($projectTypes->isEmpty()) {
            $projectTypes->push('NSO');
        }
        if ($projectTemplate) {
            $projectTypes->push($projectTemplate->project_type);
        }
        $projectTypes = $projectTypes->filter()->unique()->values();

        $storeClasses = collect(ReferenceOption::valuesOfType('store_class'));
        if ($storeClasses->isEmpty()) {
            $storeClasses = collect(['Regular', 'Kitchen', 'Both']);
        }
        if ($projectTemplate) {
            $storeClasses->push($projectTemplate->store_class);
        }
        $storeClasses = $storeClasses->filter()->unique()->values();

        $departmentOptions = collect($this->departmentOptions());
        $departments = $departmentOptions->pluck('name');
        $subUnits = $departmentOptions->pluck('sub_units')->flatten();

        if ($projectTemplate) {
            $departments = $departments->merge($projectTemplate->activities->pluck('department'));
            $subUnits = $subUnits->merge($projectTemplate->activities->pluck('sub_unit'));
        }

        $departments = $departments->filter()->unique()->sort()->values();
        $subUnits = $subUnits->filter()->unique()->sort()->values();

        $listColumns = [
            'A' => ['Project Types', $projectTypes],
            'B' => ['Store Classes', $storeClasses],
            'C' => ['Departments', $departments],
            'D' => ['Sub Units', $subUnits],
        ];

        foreach ($listColumns as $column => [$heading, $values]) {
            $listsSheet->setCellValue("{$column}1", $heading);
            foreach ($values as $index => $value) {
                $listsSheet->setCellValue($column.($index + 2), $value);
            }
        }

        return [$projectTypes, $storeClasses, $departments, $subUnits];
    }

    private function applyImportDropdowns(
        Worksheet $sheet,
        $projectTypes,
        $storeClasses,
        $departments,
        $subUnits
    ): void {
        $this->applyImportListValidation($sheet, 'B', 'A', $projectTypes->count());
        $this->applyImportListValidation($sheet, 'C', 'B', $storeClasses->count());
        $this->applyImportRangeListValidation($sheet, 'E', 'D');
        $this->applyImportListValidation($sheet, 'M', 'C', $departments->count(), true);
        $this->applyImportListValidation($sheet, 'N', 'D', $subUnits->count(), true);
    }

    private function applyImportListValidation(
        Worksheet $sheet,
        string $targetColumn,
        string $listColumn,
        int $valueCount,
        bool $allowBlank = false
    ): void {
        $lastListRow = max(2, $valueCount + 1);
        $validation = $sheet->getCell("{$targetColumn}2")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank($allowBlank)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setShowInputMessage(true)
            ->setErrorTitle('Invalid value')
            ->setError('Select a value from the list.')
            ->setPromptTitle('Select a value')
            ->setPrompt('Choose an option from the dropdown list.')
            ->setFormula1(sprintf('Lists!$%1$s$2:$%1$s$%2$d', $listColumn, $lastListRow))
            ->setSqref("{$targetColumn}2:{$targetColumn}1000");
    }

    private function applyImportRangeListValidation(
        Worksheet $sheet,
        string $targetColumn,
        string $sourceColumn
    ): void {
        $validation = $sheet->getCell("{$targetColumn}2")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setShowInputMessage(true)
            ->setErrorTitle('Invalid row key')
            ->setError('Select a Row Key from the dropdown list or leave this cell blank.')
            ->setPromptTitle('Optional parent row')
            ->setPrompt('Choose the parent activity Row Key for sub-tasks.')
            ->setFormula1(sprintf('$%1$s$2:$%1$s$1000', $sourceColumn))
            ->setSqref("{$targetColumn}2:{$targetColumn}1000");
    }

    private function departmentOptions(): array
    {
        $departments = \App\Models\Department::where('is_active', true)->orderBy('name')->get();
        $allNodes = \App\Models\DepartmentNode::where('is_active', true)->get();

        return $departments->map(function ($dept) use ($allNodes) {
            $deptNodes = $allNodes->where('department_id', $dept->id);
            
            $subUnits = $deptNodes->map(function ($node) use ($allNodes) {
                $pathParts = [];
                $current = $node;
                while ($current) {
                    array_unshift($pathParts, $current->name);
                    $parentId = $current->parent_id;
                    $current = $parentId ? $allNodes->firstWhere('id', $parentId) : null;
                }
                return implode(' > ', $pathParts);
            })->filter()->unique()->sort()->values()->all();

            return [
                'name' => $dept->name,
                'sub_units' => $subUnits,
            ];
        })->values()->all();
    }
}
