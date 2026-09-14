<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

/**
 * Imports activities and sub-tasks into ONE existing milestone of a project's
 * Gantt (/projects/{id}?tab=gantt → milestone header → Import).
 *
 * The workbook is a trimmed-down form of References/linkportal_milestone_
 * activity_import_template.xlsx, cut to what project_tasks can actually hold:
 *
 *  - The milestone is whichever header's Import button was clicked, so the
 *    file carries no milestone column.
 *  - Code / Parent Code only link rows inside the file (blank parent = activity,
 *    a code = sub-task under it). They are not stored.
 *  - The Gantt derives every date from the chain, so there is no start offset —
 *    only Lead Time, one Depends On code and Run Parallel (Yes = starts with its
 *    dependency, No = starts the day after it finishes). A blank Depends On
 *    follows the row above, exactly like a hand-added row.
 *
 * Rows upsert by name: an activity with the same name in the milestone, or a
 * sub-task with the same name under the same activity, is updated rather than
 * duplicated. Nothing already in the milestone is ever removed.
 *
 * The whole file is validated first; any error rejects the import untouched.
 */
class MilestoneActivityImportService
{
    public const SHEET = 'Import';

    /** header => [required, template column width, help text] */
    public const COLUMNS = [
        'Code' => [true, 12, 'Any unique short code for this row, e.g. A-01. Used only to link rows inside this file.'],
        'Parent Code' => [false, 13, 'Blank for an activity. For a sub-task, the Code of its activity (sub-tasks cannot have sub-tasks).'],
        'Name' => [true, 48, 'Activity or sub-task name. Re-importing the same name updates that row instead of adding a copy.'],
        'Lead Time (Days)' => [true, 16, 'Whole number, 1 or more. Counts the start day. An activity with sub-tasks uses the sum of its sub-tasks instead.'],
        'Depends On Code' => [false, 16, 'Code of ONE row in this file it waits for. Blank = follows the row above it.'],
        'Run Parallel' => [false, 13, 'Yes = starts the same day as its dependency. No (default) = starts the working day after it finishes.'],
        'Responsible Email' => [false, 28, 'Email of an existing user to assign. Blank leaves the row unassigned (or keeps the current assignee on update).'],
        'Acceptance Criteria' => [false, 45, 'Optional. What must be true to mark the row complete.'],
        'Remarks' => [false, 45, 'Optional. Saved as the row\'s comments.'],
    ];

    public function __construct(
        private ProjectScheduler $scheduler,
        private ProjectTaskBoardSyncService $projectTaskBoards,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  sample rows keyed by header
     */
    public function buildTemplate(?string $milestone = null, array $rows = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        $headers = array_keys(self::COLUMNS);
        $lastColumn = chr(ord('A') + count($headers) - 1);

        foreach ($headers as $index => $header) {
            $column = chr(ord('A') + $index);
            [$required, $width] = self::COLUMNS[$header];
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getColumnDimension($column)->setWidth($width);
            $sheet->getStyle("{$column}1")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($required ? 'FF4338CA' : 'FF64748B');
        }

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        foreach (array_values($rows) as $offset => $row) {
            foreach ($headers as $index => $header) {
                $value = $row[$header] ?? null;
                if ($value !== null && $value !== '') {
                    $sheet->setCellValue(chr(ord('A') + $index).($offset + 2), $value);
                }
            }
        }

        $parallel = new DataValidation;
        $parallel->setType(DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Run Parallel')
            ->setError('Choose Yes or No.')
            ->setFormula1('"Yes,No"');
        $sheet->setDataValidation('F2:F2000', $parallel);

        $leadTime = new DataValidation;
        $leadTime->setType(DataValidation::TYPE_WHOLE)
            ->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Lead Time (Days)')
            ->setError('Enter a whole number of 1 or more.')
            ->setFormula1('1');
        $sheet->setDataValidation('D2:D2000', $leadTime);

        $help = $spreadsheet->createSheet();
        $help->setTitle('Instructions');
        $help->setCellValue('A1', 'Gantt Milestone Import');
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $lines = [
            ['Where', 'Project → Gantt tab → a milestone header → Import. Rows go into THAT milestone only.'],
            ['Milestone', $milestone ? "This copy was downloaded for: {$milestone}" : 'Chosen by the Import button you click — the file has no milestone column.'],
            ['Sheet', 'Only the "'.self::SHEET.'" sheet is read. Row 1 must keep the column names.'],
            ['Order', 'Rows keep their order in the file. List each activity before its sub-tasks.'],
            ['Dates', 'No dates are typed. The Gantt computes them from the project Day 1 Date, Lead Time, Depends On and Run Parallel.'],
            ['Updating', 'Same name in the same place = update. Rows not in the file are left alone; nothing is deleted.'],
            ['Safety', 'If any row has an error, nothing is imported and every error is listed.'],
            ['', ''],
            ['Column', 'Rule'],
        ];
        foreach (self::COLUMNS as $header => [$required, , $text]) {
            $lines[] = [$header.($required ? ' *' : ''), $text];
        }
        foreach ($lines as $offset => [$label, $text]) {
            $help->setCellValue('A'.($offset + 3), $label);
            $help->setCellValue('B'.($offset + 3), $text);
        }
        $help->getStyle('A3:A'.(count($lines) + 2))->getFont()->setBold(true);
        $help->getStyle('A11:B11')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E7FF');
        $help->getColumnDimension('A')->setWidth(22);
        $help->getColumnDimension('B')->setWidth(110);
        $help->getStyle('B:B')->getAlignment()->setWrapText(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @return array{ok: bool, errors: array<int, string>, added: int, updated: int, task_ids: array<int, int>}
     */
    public function import(Project $project, string $category, string $path, User $actor): array
    {
        $parsed = $this->parse($path);

        if ($parsed['errors'] !== []) {
            return $this->failed($parsed['errors']);
        }

        $rows = $parsed['rows'];
        $errors = $this->validateRows($rows);

        if ($errors !== []) {
            return $this->failed($errors);
        }

        $emails = collect($rows)->pluck('email')->filter()->map(fn ($email) => Str::lower($email))->unique();
        $users = $emails->isEmpty()
            ? collect()
            : User::query()->whereIn(DB::raw('LOWER(email)'), $emails->all())->get(['id', 'email'])
                ->keyBy(fn (User $user) => Str::lower($user->email));

        foreach ($rows as $row) {
            if ($row['email'] && ! $users->has(Str::lower($row['email']))) {
                $errors[] = "Row {$row['line']}: no user has the email \"{$row['email']}\".";
            }
        }

        if ($errors !== []) {
            return $this->failed($errors);
        }

        [$added, $updated, $taskIds] = DB::transaction(
            fn () => $this->apply($project, $category, $rows, $users, $actor)
        );

        $changedIds = $this->scheduler->reschedule($project);
        $this->projectTaskBoards->syncProjectTaskChanges(
            $project,
            collect($changedIds)->merge($taskIds),
            $actor,
            false
        );

        return ['ok' => true, 'errors' => [], 'added' => $added, 'updated' => $updated, 'task_ids' => $taskIds];
    }

    private function failed(array $errors): array
    {
        return ['ok' => false, 'errors' => array_values($errors), 'added' => 0, 'updated' => 0, 'task_ids' => []];
    }

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>} */
    private function parse(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            return ['rows' => [], 'errors' => ['The file could not be read. Upload the .xlsx template.']];
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET) ?? $spreadsheet->getSheet(0);
        $table = $sheet->toArray(null, true, false, false);
        $header = array_shift($table) ?? [];

        $indexes = [];
        foreach ($header as $index => $label) {
            $indexes[$this->normalizeHeader($label)] = $index;
        }

        $missing = collect(self::COLUMNS)
            ->filter(fn (array $column) => $column[0])
            ->keys()
            ->reject(fn (string $name) => array_key_exists($this->normalizeHeader($name), $indexes))
            ->values()
            ->all();

        if ($missing !== []) {
            return ['rows' => [], 'errors' => ['Missing required columns: '.implode(', ', $missing).'.']];
        }

        $cell = function (array $line, string $name) use ($indexes) {
            $index = $indexes[$this->normalizeHeader($name)] ?? null;
            $value = $index === null ? null : ($line[$index] ?? null);

            return is_string($value) ? trim($value) : $value;
        };

        $rows = [];
        foreach ($table as $offset => $line) {
            if (collect($line)->every(fn ($value) => blank($value))) {
                continue;
            }

            $rows[] = [
                'line' => $offset + 2,
                'code' => (string) ($cell($line, 'Code') ?? ''),
                'parent' => (string) ($cell($line, 'Parent Code') ?? ''),
                'name' => (string) ($cell($line, 'Name') ?? ''),
                'lead_time' => $cell($line, 'Lead Time (Days)'),
                'depends_on' => (string) ($cell($line, 'Depends On Code') ?? ''),
                'parallel' => (string) ($cell($line, 'Run Parallel') ?? ''),
                'email' => (string) ($cell($line, 'Responsible Email') ?? ''),
                'acceptance' => (string) ($cell($line, 'Acceptance Criteria') ?? ''),
                'remarks' => (string) ($cell($line, 'Remarks') ?? ''),
            ];
        }

        if ($rows === []) {
            return ['rows' => [], 'errors' => ['The Import sheet has no rows to import.']];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    private function normalizeHeader(mixed $label): string
    {
        return Str::of((string) $label)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function codeKey(string $code): string
    {
        return Str::upper(trim($code));
    }

    /** Every structural rule, checked across the whole file before anything is written. */
    private function validateRows(array &$rows): array
    {
        $errors = [];
        $byCode = [];

        foreach ($rows as $index => $row) {
            if ($row['code'] === '') {
                continue;
            }
            $key = $this->codeKey($row['code']);
            if (isset($byCode[$key])) {
                $errors[] = "Row {$row['line']}: Code \"{$row['code']}\" is already used on row {$rows[$byCode[$key]]['line']}.";
            } else {
                $byCode[$key] = $index;
            }
        }

        $namesSeen = [];

        foreach ($rows as $index => &$row) {
            $at = "Row {$row['line']}";

            if ($row['code'] === '') {
                $errors[] = "{$at}: Code is required.";
            }
            if ($row['name'] === '') {
                $errors[] = "{$at}: Name is required.";
            } elseif (Str::length($row['name']) > 255) {
                $errors[] = "{$at}: Name is longer than 255 characters.";
            }

            $lead = $row['lead_time'];
            if ($lead === null || $lead === '' || ! is_numeric($lead) || (float) $lead != (int) $lead || (int) $lead < 1) {
                $errors[] = "{$at}: Lead Time (Days) must be a whole number of 1 or more.";
            } else {
                $row['lead_time'] = (int) $lead;
            }

            $parallel = Str::lower($row['parallel']);
            if (! in_array($parallel, ['', 'yes', 'no', 'y', 'n', 'true', 'false', '1', '0'], true)) {
                $errors[] = "{$at}: Run Parallel must be Yes or No.";
            }
            $row['parallel'] = in_array($parallel, ['yes', 'y', 'true', '1'], true);

            if ($row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$at}: Responsible Email \"{$row['email']}\" is not a valid email.";
            }

            if ($row['parent'] !== '') {
                $parentIndex = $byCode[$this->codeKey($row['parent'])] ?? null;

                if ($parentIndex === null) {
                    $errors[] = "{$at}: Parent Code \"{$row['parent']}\" does not match any Code in the file.";
                } elseif ($parentIndex === $index) {
                    $errors[] = "{$at}: a row cannot be its own parent.";
                } elseif ($rows[$parentIndex]['parent'] !== '') {
                    $errors[] = "{$at}: Parent Code \"{$row['parent']}\" is itself a sub-task. Only one sub-task level is supported.";
                } elseif ($parentIndex > $index) {
                    $errors[] = "{$at}: its activity \"{$row['parent']}\" must be listed above it.";
                }
            }

            if ($row['depends_on'] !== '') {
                $dependencyIndex = $byCode[$this->codeKey($row['depends_on'])] ?? null;

                if (str_contains($row['depends_on'], ',') || str_contains($row['depends_on'], ';')) {
                    $errors[] = "{$at}: Depends On Code takes ONE code. The Gantt supports a single dependency per row — use the one that finishes last.";
                } elseif ($dependencyIndex === null) {
                    $errors[] = "{$at}: Depends On Code \"{$row['depends_on']}\" does not match any Code in the file.";
                } elseif ($dependencyIndex === $index) {
                    $errors[] = "{$at}: a row cannot depend on itself.";
                } elseif ($row['parent'] !== '' && $this->codeKey($row['parent']) === $this->codeKey($row['depends_on'])) {
                    $errors[] = "{$at}: a sub-task already starts with its own activity — leave Depends On blank or pick a sibling.";
                } elseif ($row['parent'] === '' && $rows[$dependencyIndex]['parent'] !== ''
                    && $this->codeKey($rows[$dependencyIndex]['parent']) === $this->codeKey($row['code'])) {
                    $errors[] = "{$at}: an activity cannot depend on one of its own sub-tasks.";
                }
            }

            $nameKey = $this->codeKey($row['parent']).'|'.Str::lower($row['name']);
            if ($row['name'] !== '' && isset($namesSeen[$nameKey])) {
                $errors[] = "{$at}: \"{$row['name']}\" appears twice in the same place (row {$namesSeen[$nameKey]}). Names must be unique there because re-imports match by name.";
            }
            $namesSeen[$nameKey] = $row['line'];
        }
        unset($row);

        if ($errors === []) {
            $errors = $this->cycleErrors($rows, $byCode);
        }

        return $errors;
    }

    private function cycleErrors(array $rows, array $byCode): array
    {
        foreach ($rows as $start => $row) {
            $seen = [$start => true];
            $cursor = $row;

            while ($cursor['depends_on'] !== '') {
                $next = $byCode[$this->codeKey($cursor['depends_on'])];
                if (isset($seen[$next])) {
                    return ["Row {$row['line']}: Depends On forms a loop (\"{$row['code']}\" eventually waits for itself)."];
                }
                $seen[$next] = true;
                $cursor = $rows[$next];
            }
        }

        return [];
    }

    private function apply(Project $project, string $category, array $rows, $users, User $actor): array
    {
        $existing = ProjectTask::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($category) {
                $query->where('category', $category);
                if ($category === 'General') {
                    $query->orWhereNull('category')->orWhere('category', '');
                }
            })
            ->get();

        $activities = $existing->whereNull('parent_task_id')->keyBy(fn (ProjectTask $task) => Str::lower($task->name));
        $milestoneOrder = $existing->whereNull('parent_task_id')->min('milestone_order')
            ?? ((int) ProjectTask::where('project_id', $project->id)->whereNull('parent_task_id')->max('milestone_order')) + 1;
        $nextRootOrder = ((float) $existing->whereNull('parent_task_id')->max('order')) + 1;

        $idByCode = [];
        $added = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $isSubTask = $row['parent'] !== '';
            $parentId = $isSubTask ? $idByCode[$this->codeKey($row['parent'])] : null;

            $task = $isSubTask
                ? ProjectTask::where('parent_task_id', $parentId)->get()
                    ->first(fn (ProjectTask $candidate) => Str::lower($candidate->name) === Str::lower($row['name']))
                : $activities->get(Str::lower($row['name']));

            $values = [
                'name' => $row['name'],
                'lead_time_days' => $row['lead_time'],
                'can_run_parallel' => $row['parallel'],
                'updated_by' => $actor->id,
            ];

            if ($row['email'] !== '') {
                $values['assigned_to'] = $users->get(Str::lower($row['email']))->id;
                $values['external_assignment'] = null;
            }
            if ($row['acceptance'] !== '') {
                $values['acceptance_criteria'] = $row['acceptance'];
            }
            if ($row['remarks'] !== '') {
                $values['comments'] = $row['remarks'];
            }

            if ($task) {
                $task->fill($values)->save();
                $updated++;
            } else {
                $order = $isSubTask
                    ? ((float) ProjectTask::where('parent_task_id', $parentId)->max('order')) + 1
                    : $nextRootOrder++;

                $task = ProjectTask::create($values + [
                    'project_id' => $project->id,
                    'parent_task_id' => $parentId,
                    'category' => $category,
                    'milestone_order' => $milestoneOrder,
                    'status' => 'Pending',
                    'progress' => 0,
                    'order' => $order,
                    'created_by' => $actor->id,
                ]);
                $added++;
            }

            $idByCode[$this->codeKey($row['code'])] = $task->id;
        }

        // Dependencies last, once every code in the file has a row id.
        foreach ($rows as $row) {
            $dependsOn = $row['depends_on'] !== '' ? $idByCode[$this->codeKey($row['depends_on'])] : null;
            ProjectTask::whereKey($idByCode[$this->codeKey($row['code'])])
                ->update(['depends_on_task_id' => $dependsOn]);
        }

        return [$added, $updated, array_values($idByCode)];
    }
}
