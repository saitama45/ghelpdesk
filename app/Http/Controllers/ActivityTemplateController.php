<?php

namespace App\Http\Controllers;

use App\Models\ActivityTemplate;
use App\Models\Company;
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
        $query = ProjectTemplate::with(['activities', 'entityCompany:id,name,code', 'brandCompany:id,name,code']);

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
            'entities' => Company::where('type', 'Entity')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'brands' => Company::with('entities:id')->where('type', 'Brand')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
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
            ['2. Repeat Template Name, Project Type, Entity Code, Brand Code, Project Name, and Store Class on every activity row.'],
            ['3. Row Key must be unique within a template. Use Parent Row Key for a sub-task.'],
            ['4. Only one sub-task level is supported; a sub-task cannot be another row\'s parent.'],
            ['5. Existing templates with the same name, project type, and store class are skipped.'],
            ['6. Duration Days must be greater than 0. On a parent row it is ignored and recomputed as the sum of its sub-tasks.'],
            ['7. Can Run Parallel = No starts the row the day after its dependency finishes; Yes starts it on the same day its dependency starts.'],
            ['8. Start and Finish are calculated, never imported: Finish = Start + Duration Days - 1.'],
            ['9. Weights are percentages: milestones total 100 per template, activities total 100 per milestone, and sub-tasks total 100 per activity.'],
            ['10. Activity Mode = Per Store creates one activity per selected store when the template is applied.'],
            ['11. Remove the example rows before importing your own data.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(110);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        [$projectTypes, $storeClasses, $departments, $subUnits] = $this->addImportListSheet($spreadsheet, 2);

        $headers = $this->importHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            ['New Store Opening', $projectTypes->first(), null, null, 'LINK HUB', $storeClasses->first(), 'ACT-1', null, 'Prepare site', 'Standard', 'Preparation', 1, 100, 100, null, 'Site is ready for deployment.', null, null, 1, 'Project Team', $departments->first(), $subUnits->first(), 2, 1, null, 'No'],
            ['New Store Opening', $projectTypes->first(), null, null, 'LINK HUB', $storeClasses->first(), 'SUB-1', 'ACT-1', 'Confirm site readiness', 'Standard', 'Preparation', 1, 100, 100, 100, 'Readiness checklist is approved.', null, null, 1, 'Project Team', $departments->first(), $subUnits->first(), 1, 1, null, 'No'],
            // A requisite + Yes: this row starts the same day ACT-1 starts, so
            // the two run side by side.
            ['New Store Opening', $projectTypes->first(), null, null, 'LINK HUB', $storeClasses->first(), 'ACT-2', null, 'Order signage', 'Standard', 'Preparation', 1, 100, 100, null, 'Signage order is confirmed.', null, null, 1, 'Project Team', $departments->first(), $subUnits->first(), 3, 2, 'ACT-1', 'Yes'],
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

        $optional = collect($this->optionalImportHeaders())
            ->map(fn (string $header) => $this->normalizeImportValue($header))
            ->all();

        $missingHeaders = collect($this->importHeaders())
            ->reject(fn (string $header) => array_key_exists($this->normalizeImportValue($header), $headerIndexes)
                || in_array($this->normalizeImportValue($header), $optional, true))
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
                // An older workbook simply has no column for the optional
                // headers, so there is no index to look up.
                $columnIndex = $headerIndexes[$this->normalizeImportValue($header)] ?? null;
                $value = $columnIndex !== null ? ($row[$columnIndex] ?? null) : null;
                $data[$header] = is_string($value) ? trim($value) : $value;
            }

            $identityValidator = Validator::make($data, [
                'Template Name' => 'required|string|max:255',
                'Project Type' => 'required|string|max:100',
                'Store Class' => 'required|string|max:100',
                'Project Name' => 'nullable|string|max:255',
                'Entity Code' => 'nullable|string|max:50',
                'Brand Code' => 'nullable|string|max:50',
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
            $groups[$identity]['entity_code'] = blank($data['Entity Code']) ? null : $data['Entity Code'];
            $groups[$identity]['brand_code'] = blank($data['Brand Code']) ? null : $data['Brand Code'];
            $groups[$identity]['project_name'] = blank($data['Project Name']) ? null : $data['Project Name'];
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

            [$context, $contextErrors] = $this->resolveImportContext($group);
            if ($contextErrors !== []) {
                $skippedTemplates++;
                foreach ($contextErrors as $message) {
                    $errors[] = "{$label}: {$message}";
                }
                continue;
            }

            try {
                DB::transaction(function () use ($group, $activities, $context) {
                    $projectTemplate = ProjectTemplate::create([
                        'name' => $group['name'],
                        'project_type' => $group['project_type'],
                        'store_class' => $group['store_class'],
                        'entity_company_id' => $context['entity_company_id'],
                        'brand_company_id' => $context['brand_company_id'],
                        'project_name' => $group['project_name'],
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
        $activity_template->load(['activities', 'entityCompany:id,code', 'brandCompany:id,code']);

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
                $activity_template->entityCompany?->code,
                $activity_template->brandCompany?->code,
                $activity_template->project_name,
                $activity_template->store_class,
                'ACT-'.$activity->id,
                $activity->parent_activity_template_id ? 'ACT-'.$activity->parent_activity_template_id : null,
                $activity->activity,
                Str::headline($activity->activity_mode ?: 'standard'),
                $activity->milestone,
                $activity->milestone_order,
                $activity->milestone_weight,
                $activity->activity_weight,
                $activity->sub_task_weight,
                $activity->acceptance_criteria,
                $activity->asset_item,
                $activity->model_specs,
                $activity->qty,
                $activity->responsible,
                $activity->department,
                $activity->sub_unit,
                $activity->default_duration_days,
                $activity->order,
                $activity->depends_on_template_id ? 'ACT-'.$activity->depends_on_template_id : null,
                $activity->can_run_parallel ? 'Yes' : 'No',
            ];
        })->all();

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
            $sheet->getStyle('X2:X'.(count($rows) + 1))->getNumberFormat()->setFormatCode('0.0#');
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
            'entity_company_id' => 'nullable|exists:companies,id',
            'brand_company_id' => 'nullable|exists:companies,id',
            'project_name' => 'nullable|string|max:255',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.milestone_order' => 'nullable|integer|min:0',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'nullable|integer|min:1',
            'activities.*.depends_on_client_key' => 'nullable|string|max:255',
            'activities.*.can_run_parallel' => 'nullable|boolean',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|numeric|min:1',
            'activities.*.activity_mode' => 'nullable|in:standard,per_store',
            'activities.*.milestone_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.activity_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.sub_task_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.acceptance_criteria' => 'nullable|string|max:4000',
        ]);

        $this->validateTemplateContext($validated);

        DB::transaction(function () use ($validated) {
            $projectTemplate = ProjectTemplate::create([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
                'entity_company_id' => $validated['entity_company_id'] ?? null,
                'brand_company_id' => $validated['brand_company_id'] ?? null,
                'project_name' => $validated['project_name'] ?? null,
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
            'entity_company_id' => 'nullable|exists:companies,id',
            'brand_company_id' => 'nullable|exists:companies,id',
            'project_name' => 'nullable|string|max:255',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.milestone_order' => 'nullable|integer|min:0',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'nullable|integer|min:1',
            'activities.*.depends_on_client_key' => 'nullable|string|max:255',
            'activities.*.can_run_parallel' => 'nullable|boolean',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|numeric|min:1',
            'activities.*.activity_mode' => 'nullable|in:standard,per_store',
            'activities.*.milestone_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.activity_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.sub_task_weight' => 'nullable|numeric|min:0|max:100',
            'activities.*.acceptance_criteria' => 'nullable|string|max:4000',
        ]);

        $this->validateTemplateContext($validated);

        DB::transaction(function () use ($validated, $activity_template) {
            $activity_template->update([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
                'entity_company_id' => $validated['entity_company_id'] ?? null,
                'brand_company_id' => $validated['brand_company_id'] ?? null,
                'project_name' => $validated['project_name'] ?? null,
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
                $activity['depends_on_client_key'] = filled($activity['depends_on_client_key'] ?? null)
                    ? (string) $activity['depends_on_client_key']
                    : null;

                return $activity;
            });
        $activities = $this->assignMissingMilestoneOrders($activities);
        $activities = $this->rollUpParentLeadTimes($activities);

        $this->validateActivityHierarchy($projectTemplate, $activities);
        $weightErrors = $this->validateImportWeights($activities);
        if ($weightErrors !== []) {
            throw ValidationException::withMessages(['activities' => $weightErrors]);
        }

        $submittedIds = $activities->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $existingIds = $projectTemplate->activities()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $removedIds = array_values(array_diff($existingIds, $submittedIds));

        if (!empty($removedIds)) {
            // Template rows hard-delete, so anything still pointing at a removed
            // row would trip the requisite FK — unpoint them first.
            ActivityTemplate::whereIn('depends_on_template_id', $removedIds)
                ->update(['depends_on_template_id' => null]);

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

        // A requisite may point at any row, including one further down the
        // grid, so it can only be wired once every row has an id.
        foreach ($activities as $activity) {
            $model = $savedByClientKey[$activity['client_key']] ?? null;

            if (!$model) {
                continue;
            }

            $requisiteKey = $activity['depends_on_client_key'] ?? null;
            $requisite = $requisiteKey && $requisiteKey !== $activity['client_key']
                ? ($savedByClientKey[$requisiteKey] ?? null)
                : null;

            if ((int) $model->depends_on_template_id !== (int) $requisite?->id) {
                $model->update(['depends_on_template_id' => $requisite?->id]);
            }
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

    /**
     * A milestone's Lead Time is never typed in — it is the sum of its
     * sub-tasks' lead times (Business Requirement, "Developer Logic" rule 5).
     * The grid disables the input, but an Excel import can still carry a stale
     * figure, so the sum is re-derived here for every parent row.
     */
    private function rollUpParentLeadTimes($activities)
    {
        $sumByParent = $activities
            ->filter(fn (array $activity) => filled($activity['parent_client_key'] ?? null))
            ->groupBy('parent_client_key')
            ->map(fn ($children) => $children->sum(fn (array $child) => max(1, (int) $child['default_duration_days'])));

        return $activities->map(function (array $activity) use ($sumByParent) {
            $total = $sumByParent[$activity['client_key']] ?? null;

            if ($total !== null) {
                $activity['default_duration_days'] = $total;
            }

            return $activity;
        });
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
            // Qty left the template grid when Start/Finish took its column, but
            // it still feeds ProjectTask asset rows — default it rather than
            // letting a NULL through.
            'qty' => $activity['qty'] ?? 1,
            'responsible' => $activity['responsible'] ?? null,
            'department' => blank($activity['department'] ?? null) ? null : $activity['department'],
            'sub_unit' => blank($activity['sub_unit'] ?? null) ? null : $activity['sub_unit'],
            'default_duration_days' => $activity['default_duration_days'],
            'can_run_parallel' => (bool) ($activity['can_run_parallel'] ?? false),
            'activity_mode' => $activity['activity_mode'] ?? 'standard',
            'milestone_weight' => $activity['milestone_weight'] ?? null,
            'activity_weight' => $activity['activity_weight'] ?? null,
            'sub_task_weight' => $activity['sub_task_weight'] ?? null,
            'acceptance_criteria' => blank($activity['acceptance_criteria'] ?? null) ? null : $activity['acceptance_criteria'],
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
            'Entity Code',
            'Brand Code',
            'Project Name',
            'Store Class',
            'Row Key',
            'Parent Row Key',
            'Activity',
            'Activity Mode',
            'Milestone',
            'Milestone Order',
            'Milestone Weight %',
            'Activity Weight %',
            'Sub-Task Weight %',
            'Acceptance Criteria',
            'Asset Item',
            'Model Specs',
            'Quantity',
            'Responsible',
            'Department',
            'Sub Unit',
            'Duration Days',
            'Order',
            'Requisite Row Key',
            'Can Run Parallel',
        ];
    }

    /**
     * Headers added after the import format shipped. They are written into every
     * export and blank template, but a workbook saved before they existed must
     * still import cleanly — so they are never required.
     */
    private function optionalImportHeaders(): array
    {
        return [
            'Entity Code', 'Brand Code', 'Project Name', 'Activity Mode',
            'Milestone Weight %', 'Activity Weight %', 'Sub-Task Weight %',
            'Acceptance Criteria', 'Requisite Row Key', 'Can Run Parallel',
        ];
    }

    /** Excel carries Yes/No; the column is a boolean underneath. */
    private function parseImportBoolean(mixed $value): bool
    {
        return in_array($this->normalizeImportValue($value), ['yes', 'y', 'true', '1'], true);
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
                'activity_mode' => blank($data['Activity Mode']) ? 'standard' : Str::snake($data['Activity Mode']),
                'milestone' => blank($data['Milestone']) ? 'General' : $data['Milestone'],
                'milestone_order' => blank($data['Milestone Order']) ? null : $data['Milestone Order'],
                'milestone_weight' => blank($data['Milestone Weight %']) ? null : $data['Milestone Weight %'],
                'activity_weight' => blank($data['Activity Weight %']) ? null : $data['Activity Weight %'],
                'sub_task_weight' => blank($data['Sub-Task Weight %']) ? null : $data['Sub-Task Weight %'],
                'acceptance_criteria' => blank($data['Acceptance Criteria']) ? null : $data['Acceptance Criteria'],
                'asset_item' => blank($data['Asset Item']) ? null : $data['Asset Item'],
                'model_specs' => blank($data['Model Specs']) ? null : $data['Model Specs'],
                'qty' => $data['Quantity'],
                'responsible' => blank($data['Responsible']) ? null : $data['Responsible'],
                'department' => blank($data['Department']) ? null : $data['Department'],
                'sub_unit' => blank($data['Sub Unit']) ? null : $data['Sub Unit'],
                'default_duration_days' => $data['Duration Days'],
                'order' => $data['Order'],
                'depends_on_client_key' => blank($data['Requisite Row Key']) ? null : $data['Requisite Row Key'],
                'can_run_parallel' => $this->parseImportBoolean($data['Can Run Parallel']),
            ];

            $validator = Validator::make($activity, [
                'client_key' => 'required|string|max:255',
                'parent_client_key' => 'nullable|string|max:255',
                'activity' => 'required|string|max:255',
                'activity_mode' => 'required|in:standard,per_store',
                'milestone' => 'nullable|string|max:255',
                'milestone_order' => 'nullable|integer|min:0',
                'milestone_weight' => 'nullable|numeric|min:0|max:100',
                'activity_weight' => 'nullable|numeric|min:0|max:100',
                'sub_task_weight' => 'nullable|numeric|min:0|max:100',
                'acceptance_criteria' => 'nullable|string|max:4000',
                'asset_item' => 'nullable|string|max:255',
                'model_specs' => 'nullable|string|max:255',
                'qty' => 'nullable|integer|min:1',
                'responsible' => 'nullable|string|max:255',
                'department' => 'nullable|string|max:255',
                'sub_unit' => 'nullable|string|max:255',
                'default_duration_days' => 'required|integer|min:1',
                'order' => 'required|numeric|min:1',
                'depends_on_client_key' => 'nullable|string|max:255',
                'can_run_parallel' => 'boolean',
            ], [], [
                'client_key' => 'Row Key',
                'parent_client_key' => 'Parent Row Key',
                'activity' => 'Activity',
                'activity_mode' => 'Activity Mode',
                'milestone' => 'Milestone',
                'milestone_order' => 'Milestone Order',
                'milestone_weight' => 'Milestone Weight %',
                'activity_weight' => 'Activity Weight %',
                'sub_task_weight' => 'Sub-Task Weight %',
                'acceptance_criteria' => 'Acceptance Criteria',
                'asset_item' => 'Asset Item',
                'model_specs' => 'Model Specs',
                'qty' => 'Quantity',
                'responsible' => 'Responsible',
                'department' => 'Department',
                'sub_unit' => 'Sub Unit',
                'default_duration_days' => 'Duration Days',
                'order' => 'Order',
                'depends_on_client_key' => 'Requisite Row Key',
                'can_run_parallel' => 'Can Run Parallel',
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
            $activity['depends_on_client_key'] = $activity['depends_on_client_key'] === null
                ? null
                : $this->normalizeImportValue($activity['depends_on_client_key']);
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

        $errors = array_merge($errors, $this->validateImportWeights(collect($activities)));

        return [$activities, $errors];
    }

    private function validateImportWeights($activities): array
    {
        $errors = [];
        $weightedRows = $activities->filter(fn ($row) => $row['milestone_weight'] !== null
            || $row['activity_weight'] !== null || $row['sub_task_weight'] !== null);

        if ($weightedRows->isEmpty()) {
            return [];
        }

        $parents = $activities->filter(fn ($row) => empty($row['parent_client_key']));
        $milestones = $parents->groupBy(fn ($row) => $row['milestone'] ?: 'General');
        $milestoneTotal = 0.0;

        foreach ($milestones as $name => $rows) {
            $weights = $rows->pluck('milestone_weight')->filter(fn ($value) => $value !== null)->unique()->values();
            if ($weights->count() !== 1) {
                $errors[] = "Milestone '{$name}' must repeat one consistent Milestone Weight % on every row.";
                continue;
            }
            $milestoneTotal += (float) $weights->first();

            $activityTotal = (float) $rows->sum(fn ($row) => (float) ($row['activity_weight'] ?? 0));
            if (abs($activityTotal - 100) > 0.01) {
                $errors[] = "Activities in milestone '{$name}' total {$activityTotal}%; they must total 100%.";
            }
        }

        if (abs($milestoneTotal - 100) > 0.01) {
            $errors[] = "Milestone weights total {$milestoneTotal}%; they must total 100%.";
        }

        foreach ($parents as $parent) {
            $children = $activities->where('parent_client_key', $parent['client_key']);
            if ($children->isEmpty()) {
                continue;
            }
            $total = (float) $children->sum(fn ($row) => (float) ($row['sub_task_weight'] ?? 0));
            if (abs($total - 100) > 0.01) {
                $errors[] = "Sub-tasks under '{$parent['activity']}' total {$total}%; they must total 100%.";
            }
        }

        return $errors;
    }

    private function resolveImportContext(array $group): array
    {
        $errors = [];
        $entity = null;
        $brand = null;

        if (filled($group['entity_code'] ?? null)) {
            $entity = Company::where('code', $group['entity_code'])->where('type', 'Entity')->first();
            if (! $entity) {
                $errors[] = "Entity Code '{$group['entity_code']}' was not found as an Entity company.";
            }
        }

        if (filled($group['brand_code'] ?? null)) {
            $brand = Company::where('code', $group['brand_code'])->where('type', 'Brand')->first();
            if (! $brand) {
                $errors[] = "Brand Code '{$group['brand_code']}' was not found as a Brand company.";
            }
        }

        if ($entity && $brand && ! $entity->brands()->whereKey($brand->id)->exists()) {
            $errors[] = "Brand {$brand->code} is not assigned to Entity {$entity->code}.";
        }

        return [[
            'entity_company_id' => $entity?->id,
            'brand_company_id' => $brand?->id,
        ], $errors];
    }

    private function validateTemplateContext(array $validated): void
    {
        $entityId = $validated['entity_company_id'] ?? null;
        $brandId = $validated['brand_company_id'] ?? null;

        if ($entityId && ! Company::whereKey($entityId)->where('type', 'Entity')->exists()) {
            throw ValidationException::withMessages([
                'entity_company_id' => 'The selected company must be an Entity.',
            ]);
        }

        if (! $brandId) {
            return;
        }

        if (! $entityId) {
            throw ValidationException::withMessages([
                'brand_company_id' => 'Select an Entity before selecting a Brand.',
            ]);
        }

        $brandIsAssigned = DB::table('entity_brand')->where([
            'entity_company_id' => $entityId,
            'brand_company_id' => $brandId,
        ])->exists();

        if (! $brandIsAssigned) {
            throw ValidationException::withMessages([
                'brand_company_id' => 'The selected Brand is not assigned to the selected Entity.',
            ]);
        }
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
        $entities = Company::where('type', 'Entity')->where('is_active', true)->orderBy('code')->pluck('code');
        $brands = Company::where('type', 'Brand')->where('is_active', true)->orderBy('code')->pluck('code');
        $activityModes = collect(['Standard', 'Per Store']);
        $yesNo = collect(['No', 'Yes']);

        $listColumns = [
            'A' => ['Project Types', $projectTypes],
            'B' => ['Store Classes', $storeClasses],
            'C' => ['Departments', $departments],
            'D' => ['Sub Units', $subUnits],
            'E' => ['Entity Codes', $entities],
            'F' => ['Brand Codes', $brands],
            'G' => ['Activity Modes', $activityModes],
            'H' => ['Yes / No', $yesNo],
        ];

        foreach ($listColumns as $column => [$heading, $values]) {
            $listsSheet->setCellValue("{$column}1", $heading);
            foreach ($values as $index => $value) {
                $listsSheet->setCellValue($column.($index + 2), $value);
            }
        }

        return [$projectTypes, $storeClasses, $departments, $subUnits, $entities, $brands, $activityModes, $yesNo];
    }

    private function applyImportDropdowns(
        Worksheet $sheet,
        $projectTypes,
        $storeClasses,
        $departments,
        $subUnits
    ): void {
        $this->applyImportListValidation($sheet, 'B', 'A', $projectTypes->count());
        $this->applyImportListValidation($sheet, 'C', 'E', Company::where('type', 'Entity')->where('is_active', true)->count(), true);
        $this->applyImportListValidation($sheet, 'D', 'F', Company::where('type', 'Brand')->where('is_active', true)->count(), true);
        $this->applyImportListValidation($sheet, 'F', 'B', $storeClasses->count());
        $this->applyImportRangeListValidation($sheet, 'H', 'G');
        $this->applyImportListValidation($sheet, 'J', 'G', 2);
        $this->applyImportListValidation($sheet, 'U', 'C', $departments->count(), true);
        $this->applyImportListValidation($sheet, 'V', 'D', $subUnits->count(), true);
        $this->applyImportRangeListValidation($sheet, 'Y', 'G');
        $this->applyImportListValidation($sheet, 'Z', 'H', 2);
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
