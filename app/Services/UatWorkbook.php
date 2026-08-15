<?php

namespace App\Services;

use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
use App\Models\UatFinding;
use App\Models\UatParticipant;
use App\Models\UatSection;
use App\Models\UatSignoff;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads and writes the two spreadsheet shapes this module replaces.
 *
 *  Test Script  — one row per test case with a single "Actual Results" column.
 *  Walkthrough  — one row per checklist item with a Yes/No column per department.
 *
 * Both remain the format clients expect to receive, so a cycle can be exported
 * back into either at any time. Import auto-detects which one it was handed.
 */
class UatWorkbook
{
    public const FORMAT_SCRIPT = 'script';
    public const FORMAT_MATRIX = 'matrix';

    /** Header labels that identify the test-script layout. */
    private const SCRIPT_HEADERS = [
        'test case id' => 'case_key',
        'case id' => 'case_key',
        'screen' => 'screen',
        'module' => 'screen',
        'description' => 'description',
        'test steps' => 'steps',
        'steps' => 'steps',
        'expected results' => 'expected_results',
        'expected result' => 'expected_results',
        'actual results' => 'actual',
        'actual result' => 'actual',
        'remarks' => 'remarks',
        'remark' => 'remarks',
    ];

    /** Free-text verdicts seen in the wild, mapped onto stored results. */
    private const VERDICT_ALIASES = [
        'passed' => UatCaseResult::PASSED,
        'pass' => UatCaseResult::PASSED,
        'yes' => UatCaseResult::PASSED,
        'y' => UatCaseResult::PASSED,
        'ok' => UatCaseResult::PASSED,
        'failed' => UatCaseResult::FAILED,
        'fail' => UatCaseResult::FAILED,
        'no' => UatCaseResult::FAILED,
        'n' => UatCaseResult::FAILED,
        'blocked' => UatCaseResult::BLOCKED,
        'blocker' => UatCaseResult::BLOCKED,
        'ongoing' => UatCaseResult::ONGOING,
        'in progress' => UatCaseResult::ONGOING,
        'wip' => UatCaseResult::ONGOING,
        'pending' => UatCaseResult::PENDING,
        'new' => UatCaseResult::PENDING,
        'n/a' => UatCaseResult::NOT_APPLICABLE,
        'na' => UatCaseResult::NOT_APPLICABLE,
        'not applicable' => UatCaseResult::NOT_APPLICABLE,
    ];

    public function __construct(private UatService $uat) {}

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Loads cases (and, for the matrix layout, participants and verdicts) into
     * an existing cycle.
     *
     * @return array{format:string,sections:int,cases:int,participants:int,verdicts:int,skipped:int,errors:array<int,string>}
     */
    public function import(UatCycle $cycle, string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        [$headerIndex, $map, $format] = $this->detectHeader($rows);

        if ($headerIndex === null) {
            return [
                'format' => 'unknown',
                'sections' => 0, 'cases' => 0, 'participants' => 0, 'verdicts' => 0, 'skipped' => 0,
                'errors' => ['No recognisable header row was found. The sheet needs either a "Test Case ID" column or a "Title" column followed by participant columns.'],
            ];
        }

        return $format === self::FORMAT_SCRIPT
            ? $this->importScript($cycle, $rows, $headerIndex, $map)
            : $this->importMatrix($cycle, $rows, $headerIndex, $map);
    }

    /**
     * Finds the header row and works out which layout it belongs to.
     *
     * @return array{0:?int,1:array<mixed>,2:string}
     */
    private function detectHeader(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($c) => $this->normalize($c), (array) $row);

            // Test-script layout: an explicit test-case-id column.
            if (array_intersect($cells, ['test case id', 'case id'])) {
                $map = [];
                foreach ($cells as $col => $label) {
                    if (isset(self::SCRIPT_HEADERS[$label])) {
                        $map[self::SCRIPT_HEADERS[$label]] = $col;
                    }
                }

                return [$index, $map, self::FORMAT_SCRIPT];
            }

            // Walkthrough layout: a Title column with named columns after it.
            $titleCol = array_search('title', $cells, true);
            if ($titleCol !== false) {
                $participants = [];
                $remarkCol = null;

                foreach ($cells as $col => $label) {
                    if ($col <= $titleCol || $label === '') {
                        continue;
                    }
                    if (in_array($label, ['remark', 'remarks', 'comment', 'comments'], true)) {
                        $remarkCol = $col;
                        continue;
                    }
                    // Keep the original casing for the column label.
                    $participants[$col] = trim((string) $row[$col]);
                }

                if ($participants !== []) {
                    return [$index, ['title' => $titleCol, 'participants' => $participants, 'remarks' => $remarkCol], self::FORMAT_MATRIX];
                }
            }
        }

        return [null, [], 'unknown'];
    }

    /** Test-script layout: one case per row, verdict recorded against a QA column. */
    private function importScript(UatCycle $cycle, array $rows, int $headerIndex, array $map): array
    {
        $existingKeys = UatCase::where('uat_cycle_id', $cycle->id)
            ->pluck('case_key')
            ->map(fn ($k) => $this->normalize($k))
            ->flip();

        $order = (int) UatCase::where('uat_cycle_id', $cycle->id)->max('order');
        $sectionCache = $this->sectionCache($cycle);

        $imported = 0;
        $skipped = 0;
        $verdicts = 0;
        $errors = [];
        $pendingVerdicts = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex) {
                continue;
            }

            $key = trim((string) ($row[$map['case_key'] ?? -1] ?? ''));
            $screen = trim((string) ($row[$map['screen'] ?? -1] ?? ''));
            $description = trim((string) ($row[$map['description'] ?? -1] ?? ''));

            if ($key === '' && $screen === '' && $description === '') {
                continue;
            }

            if ($key === '') {
                $errors[] = 'Row '.($index + 1).': skipped, no Test Case ID.';
                $skipped++;
                continue;
            }

            if ($existingKeys->has($this->normalize($key))) {
                $skipped++;
                continue;
            }

            // The Screen column reads "Issuances - Department Orders": the part
            // before the dash is the functional area, which becomes the section.
            $sectionName = $screen !== '' ? trim(explode(' - ', $screen)[0]) : 'General';
            $section = $this->resolveSection($cycle, $sectionCache, $sectionName);

            $case = UatCase::create([
                'uat_cycle_id' => $cycle->id,
                'uat_section_id' => $section?->id,
                'case_key' => $key,
                'screen' => $screen ?: null,
                'title' => $screen !== '' ? $screen : $key,
                'description' => $description ?: null,
                'steps' => trim((string) ($row[$map['steps'] ?? -1] ?? '')) ?: null,
                'expected_results' => trim((string) ($row[$map['expected_results'] ?? -1] ?? '')) ?: null,
                'is_critical' => true,
                'priority' => 'medium',
                'order' => ++$order,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $existingKeys->put($this->normalize($key), true);
            $imported++;

            $actual = $this->toVerdict($row[$map['actual'] ?? -1] ?? null);
            $remarks = trim((string) ($row[$map['remarks'] ?? -1] ?? ''));

            if ($actual !== null || $remarks !== '') {
                $pendingVerdicts[] = [
                    'case_id' => $case->id,
                    'result' => $actual ?? UatCaseResult::PENDING,
                    'remarks' => $remarks ?: null,
                ];
            }
        }

        if ($pendingVerdicts !== []) {
            // The single "Actual Results" column belongs to whoever ran the sheet;
            // park it on a QA column so the matrix stays well-formed.
            $qa = $this->resolveImportParticipant($cycle, 'QA');
            $this->uat->ensureCells($cycle);

            foreach ($pendingVerdicts as $entry) {
                UatCaseResult::updateOrCreate(
                    ['uat_case_id' => $entry['case_id'], 'uat_participant_id' => $qa->id],
                    [
                        'uat_cycle_id' => $cycle->id,
                        'result' => $entry['result'],
                        'remarks' => $entry['remarks'],
                        'executed_at' => $entry['result'] === UatCaseResult::PENDING ? null : now(),
                        'executed_by_user_id' => auth()->id(),
                        'source' => 'internal',
                    ]
                );
                $verdicts++;
            }
        }

        $this->uat->ensureCells($cycle);

        return [
            'format' => self::FORMAT_SCRIPT,
            'sections' => count($sectionCache),
            'cases' => $imported,
            'participants' => $pendingVerdicts === [] ? 0 : 1,
            'verdicts' => $verdicts,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /** Walkthrough layout: a column per department, section names on their own row. */
    private function importMatrix(UatCycle $cycle, array $rows, int $headerIndex, array $map): array
    {
        $titleCol = $map['title'];
        $remarkCol = $map['remarks'];

        $participants = [];
        foreach ($map['participants'] as $col => $label) {
            $participants[$col] = $this->resolveImportParticipant($cycle, $label);
        }

        $existingKeys = UatCase::where('uat_cycle_id', $cycle->id)
            ->pluck('case_key')
            ->map(fn ($k) => $this->normalize($k))
            ->flip();

        $order = (int) UatCase::where('uat_cycle_id', $cycle->id)->max('order');
        $sectionCache = $this->sectionCache($cycle);
        $currentSection = null;
        $prefix = UatCase::keyPrefix($cycle->id);
        $sequence = $this->highestSequence($cycle);

        $imported = 0;
        $skipped = 0;
        $verdicts = 0;
        $errors = [];
        $cells = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex) {
                continue;
            }

            $title = trim((string) ($row[$titleCol] ?? ''));
            if ($title === '') {
                continue;
            }

            $hasVerdicts = false;
            foreach (array_keys($participants) as $col) {
                if (trim((string) ($row[$col] ?? '')) !== '') {
                    $hasVerdicts = true;
                    break;
                }
            }

            // A row with a label but no verdicts anywhere is a section banner
            // ("ITEM", "BILLING") rather than a checklist item.
            if (!$hasVerdicts && !preg_match('/^\s*\d+[.)]/', $title)) {
                $currentSection = $this->resolveSection($cycle, $sectionCache, $title);
                continue;
            }

            $key = sprintf('%s-%02d', $prefix, ++$sequence);
            while ($existingKeys->has($this->normalize($key))) {
                $key = sprintf('%s-%02d', $prefix, ++$sequence);
            }

            $case = UatCase::create([
                'uat_cycle_id' => $cycle->id,
                'uat_section_id' => $currentSection?->id,
                'case_key' => $key,
                'screen' => $currentSection?->name,
                'title' => $title,
                'steps' => null,
                'expected_results' => null,
                'is_critical' => $currentSection?->is_critical ?? true,
                'priority' => 'medium',
                'order' => ++$order,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $existingKeys->put($this->normalize($key), true);
            $imported++;

            $rowRemark = $remarkCol !== null ? trim((string) ($row[$remarkCol] ?? '')) : '';

            foreach ($participants as $col => $participant) {
                $verdict = $this->toVerdict($row[$col] ?? null);
                if ($verdict === null) {
                    continue;
                }

                $cells[] = [
                    'case_id' => $case->id,
                    'participant_id' => $participant->id,
                    'result' => $verdict,
                    // The single Remark column applies to the row, so it is
                    // attached to every column that actually answered.
                    'remarks' => $rowRemark ?: null,
                ];
                $verdicts++;
            }
        }

        $this->uat->ensureCells($cycle);

        foreach ($cells as $cell) {
            UatCaseResult::updateOrCreate(
                ['uat_case_id' => $cell['case_id'], 'uat_participant_id' => $cell['participant_id']],
                [
                    'uat_cycle_id' => $cycle->id,
                    'result' => $cell['result'],
                    'remarks' => $cell['remarks'],
                    'executed_at' => now(),
                    'executed_by_user_id' => auth()->id(),
                    'source' => 'internal',
                ]
            );
        }

        return [
            'format' => self::FORMAT_MATRIX,
            'sections' => count($sectionCache),
            'cases' => $imported,
            'participants' => count($participants),
            'verdicts' => $verdicts,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /** @return array<string,UatSection> keyed by normalised name */
    private function sectionCache(UatCycle $cycle): array
    {
        return UatSection::where('uat_cycle_id', $cycle->id)
            ->get()
            ->keyBy(fn ($s) => $this->normalize($s->name))
            ->all();
    }

    private function resolveSection(UatCycle $cycle, array &$cache, string $name): ?UatSection
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $key = $this->normalize($name);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $section = UatSection::create([
            'uat_cycle_id' => $cycle->id,
            'name' => mb_substr($name, 0, 255),
            'is_critical' => true,
            'order' => count($cache) + 1,
        ]);

        $cache[$key] = $section;

        return $section;
    }

    private function resolveImportParticipant(UatCycle $cycle, string $label): UatParticipant
    {
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 80) : 'Team';

        $existing = UatParticipant::where('uat_cycle_id', $cycle->id)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if ($existing) {
            return $existing;
        }

        $order = (int) UatParticipant::where('uat_cycle_id', $cycle->id)->max('order');

        return UatParticipant::create([
            'uat_cycle_id' => $cycle->id,
            'kind' => UatParticipant::KIND_DEPARTMENT,
            'label' => $label,
            'role' => UatParticipant::ROLE_TESTER,
            'is_active' => true,
            'order' => $order + 1,
        ]);
    }

    private function highestSequence(UatCycle $cycle): int
    {
        $highest = 0;
        foreach (UatCase::where('uat_cycle_id', $cycle->id)->pluck('case_key') as $key) {
            if (preg_match('/(\d+)\s*$/', (string) $key, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $highest;
    }

    private function toVerdict(mixed $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        return self::VERDICT_ALIASES[$normalized] ?? null;
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * Full workbook: the test-script sheet, the walkthrough matrix, the
     * acceptance roster and the findings register.
     */
    public function export(UatCycle $cycle): Spreadsheet
    {
        $cases = $cycle->cases()->with('section')->get();
        $participants = $cycle->participants()->with(['user:id,name,email', 'department:id,name', 'currentSignoff'])->get();
        $results = $cycle->results()->get();
        $findings = $cycle->findings()->with(['assignee:id,name', 'testCase:id,case_key', 'ticket:id,ticket_key'])->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle($cycle->title)
            ->setSubject('UAT Test Script')
            ->setCreator(config('app.name', 'Helpdesk'));

        $this->buildScriptSheet($spreadsheet->getActiveSheet(), $cycle, $cases, $results, $participants);
        $this->buildMatrixSheet($spreadsheet->createSheet(1), $cycle, $cases, $results, $participants);
        $this->buildAcceptanceSheet($spreadsheet->createSheet(2), $cycle, $participants);
        $this->buildFindingsSheet($spreadsheet->createSheet(3), $findings);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** Sheet 1 — the original test-script layout, header block and all. */
    private function buildScriptSheet(Worksheet $sheet, UatCycle $cycle, $cases, $results, $participants): void
    {
        $sheet->setTitle('Test Script');
        $stats = $this->uat->statistics($cases, $results, $participants);
        $byCase = $results->groupBy('uat_case_id');

        $links = collect($cycle->links ?? [])->pluck('url')->filter()->values();

        $sheet->setCellValue('A1', 'Test Title');
        $sheet->setCellValue('B1', $cycle->title);
        $sheet->setCellValue('A2', 'Total # of Test Cases');
        $sheet->setCellValue('B2', $stats['total_cases']);
        $sheet->setCellValue('A3', 'Test Environment');
        $sheet->setCellValue('B3', $cycle->environment);
        $sheet->setCellValue('A4', 'Assigned QA');
        $sheet->setCellValue('B4', $cycle->qaLead?->name);
        $sheet->setCellValue('A5', 'Assigned Dev');
        $sheet->setCellValue('B5', $cycle->devLead?->name);
        $sheet->setCellValue('A7', 'Links');
        $sheet->setCellValue('B7', $links->get(0));
        $sheet->setCellValue('B8', $links->get(1));

        $sheet->setCellValue('D3', 'OVERALL PROGRESS');
        $sheet->setCellValue('D4', $stats['execution_rate']);
        $sheet->getStyle('D4')->getNumberFormat()->setFormatCode('0.00%');

        $sheet->setCellValue('E3', 'TEST RESULT');
        $sheet->setCellValue('F3', $cycle->environment);
        foreach ([
            ['E4', 'PASSED', 'F4', $stats['passed']],
            ['E5', 'FAILED', 'F5', $stats['failed']],
            ['E6', 'PENDING', 'F6', $stats['pending']],
            ['E7', 'TOTAL TC EXECUTED', 'F7', $stats['executed']],
        ] as [$labelCell, $label, $valueCell, $value]) {
            $sheet->setCellValue($labelCell, $label);
            $sheet->setCellValue($valueCell, $value);
        }
        $sheet->setCellValue('E8', 'TOTAL TC EXECUTED (%)');
        $sheet->setCellValue('F8', $stats['execution_rate']);
        $sheet->getStyle('F8')->getNumberFormat()->setFormatCode('0.00%');

        $sheet->getStyle('A1:A8')->getFont()->setBold(true);
        $sheet->getStyle('D3:E8')->getFont()->setBold(true);

        $headers = ['Test Case ID', 'Screen', 'Description', 'Test Steps', 'Expected Results', 'Actual Results', 'Remarks'];
        $sheet->fromArray($headers, null, 'A11');
        $this->styleHeaderRow($sheet, 11, count($headers));

        $row = 12;
        foreach ($cases as $case) {
            $caseResults = $byCase->get($case->id, collect());
            $verdict = $this->uat->rollUp($caseResults);

            // Every participant's remark, attributed — the source sheet had one
            // anonymous Remarks cell, which lost who reported what.
            $remarks = $caseResults
                ->filter(fn ($r) => filled($r->remarks))
                ->map(function ($r) use ($participants) {
                    $label = $participants->firstWhere('id', $r->uat_participant_id)?->label ?? 'Tester';

                    return "[{$label}] ".$r->remarks;
                })->implode("\n\n");

            $sheet->fromArray([
                $case->case_key,
                $case->screen,
                $case->description,
                $case->steps,
                $case->expected_results,
                UatCaseResult::results()[$verdict] ?? $verdict,
                $remarks,
            ], null, 'A'.$row);

            $sheet->getStyle('F'.$row)->getFont()->getColor()->setARGB($this->verdictColor($verdict));
            $sheet->getStyle('F'.$row)->getFont()->setBold(true);
            $row++;
        }

        $lastRow = max(11, $row - 1);
        $sheet->getStyle("A11:G{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A11:G{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (['A' => 14, 'B' => 32, 'C' => 50, 'D' => 70, 'E' => 45, 'F' => 14, 'G' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A12');
    }

    /** Sheet 2 — the walkthrough matrix, one column per participant. */
    private function buildMatrixSheet(Worksheet $sheet, UatCycle $cycle, $cases, $results, $participants): void
    {
        $sheet->setTitle('Walkthrough Matrix');

        $columns = $participants->filter(fn ($p) => $p->is_active && $p->canRecordVerdicts())->values();
        $lookup = $results->groupBy('uat_case_id');

        $headers = array_merge(['Ref', 'Title'], $columns->pluck('label')->all(), ['Remark']);
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, 1, count($headers));

        $row = 2;
        $currentSection = null;

        foreach ($cases as $case) {
            // Section banner row, exactly as the source document laid it out.
            $sectionName = $case->section?->name ?? 'General';
            if ($sectionName !== $currentSection) {
                $currentSection = $sectionName;
                $sheet->setCellValue('A'.$row, mb_strtoupper($sectionName));
                $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($headers)).$row)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
                $sheet->getStyle('A'.$row)->getFont()->setBold(true);
                $row++;
            }

            $caseResults = $lookup->get($case->id, collect())->keyBy('uat_participant_id');

            $line = [$case->case_key, $case->title];
            foreach ($columns as $participant) {
                $result = $caseResults->get($participant->id);
                $line[] = $result ? $this->verdictShorthand($result->result) : '';
            }
            $line[] = $caseResults->filter(fn ($r) => filled($r->remarks))->pluck('remarks')->implode("\n");

            $sheet->fromArray($line, null, 'A'.$row);
            $row++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $lastRow = max(1, $row - 1);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(60);
        for ($i = 3; $i < count($headers); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(14);
        }
        $sheet->getColumnDimension($lastColumn)->setWidth(50);
        $sheet->freezePane('C2');
    }

    /** Sheet 3 — the acceptance roster and final sign-off. */
    private function buildAcceptanceSheet(Worksheet $sheet, UatCycle $cycle, $participants): void
    {
        $sheet->setTitle('Acceptance');

        $sheet->setCellValue('A1', 'User Acceptance');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headers = ['Department / Stakeholder', 'Name', 'Email', 'Date Confirmed', 'Overall Result', 'Remarks'];
        $sheet->fromArray($headers, null, 'A2');
        $this->styleHeaderRow($sheet, 2, count($headers));

        $row = 3;
        foreach ($participants->filter(fn ($p) => $p->isApprover()) as $participant) {
            $signoff = $participant->currentSignoff;

            $sheet->fromArray([
                $participant->label,
                $participant->display_name,
                $participant->display_email,
                $signoff?->confirmed_at?->format('F j, Y'),
                $signoff ? (UatSignoff::results()[$signoff->result] ?? $signoff->result) : 'Pending',
                $signoff?->remarks,
            ], null, 'A'.$row);
            $row++;
        }

        $row++;
        $sheet->setCellValue('A'.$row, 'Final Sign-off');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13);
        $row++;
        $sheet->fromArray($headers, null, 'A'.$row);
        $this->styleHeaderRow($sheet, $row, count($headers));
        $row++;

        $final = $cycle->signoffs()
            ->where('stage', UatSignoff::STAGE_FINAL)
            ->where('is_current', true)
            ->with('confirmedBy:id,name,email')
            ->first();

        $sheet->fromArray([
            $cycle->department?->name ?? 'Management',
            $final?->confirmed_name ?? $final?->confirmedBy?->name,
            $final?->confirmed_email ?? $final?->confirmedBy?->email,
            $final?->confirmed_at?->format('F j, Y'),
            $final ? (UatSignoff::results()[$final->result] ?? $final->result) : 'Pending',
            $final?->remarks,
        ], null, 'A'.$row);

        foreach (['A' => 32, 'B' => 28, 'C' => 34, 'D' => 18, 'E' => 24, 'F' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A1:F'.$row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
    }

    /** Sheet 4 — the findings register, which the source workbook had no room for. */
    private function buildFindingsSheet(Worksheet $sheet, $findings): void
    {
        $sheet->setTitle('Findings');

        $headers = ['Ref', 'Test Case', 'Title', 'Details', 'Severity', 'Status', 'Assigned To', 'Ticket', 'Reported By', 'Resolved At', 'Resolution'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, 1, count($headers));

        $row = 2;
        foreach ($findings as $finding) {
            $sheet->fromArray([
                $finding->reference,
                $finding->testCase?->case_key,
                $finding->title,
                $finding->details,
                UatFinding::severities()[$finding->severity] ?? $finding->severity,
                UatFinding::statuses()[$finding->status] ?? $finding->status,
                $finding->assignee?->name,
                $finding->ticket?->ticket_key,
                $finding->reported_by_name,
                $finding->resolved_at?->format('Y-m-d'),
                $finding->resolution_notes,
            ], null, 'A'.$row);
            $row++;
        }

        $lastRow = max(1, $row - 1);
        $sheet->getStyle("A1:K{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("A1:K{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (['A' => 10, 'B' => 14, 'C' => 40, 'D' => 55, 'E' => 12, 'F' => 14, 'G' => 22, 'H' => 16, 'I' => 22, 'J' => 14, 'K' => 40] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A2');
    }

    /** A blank workbook in the import format, with instructions. */
    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Test Script');
        $headers = ['Test Case ID', 'Screen', 'Description', 'Test Steps', 'Expected Results', 'Actual Results', 'Remarks'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, 1, count($headers));
        $sheet->fromArray([
            'UI-UX-01',
            'Issuances - Department Orders',
            'Verify the tester can filter and open a department order.',
            "1. Open Issuances → Department Orders\n2. Choose a Series\n3. Click Apply Filters",
            "* Records counter matches visible rows\n* PDF opens in a new tab",
            'Passed',
            '',
        ], null, 'A2');
        foreach (['A' => 14, 'B' => 32, 'C' => 50, 'D' => 60, 'E' => 45, 'F' => 14, 'G' => 45] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A2:G2')->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

        $matrix = $spreadsheet->createSheet(1);
        $matrix->setTitle('Walkthrough Matrix');
        $matrix->fromArray(['Title', 'BD', 'Ops Support', 'Ops', 'Accounting', 'Warehouse', 'Admin', 'Remark'], null, 'A1');
        $this->styleHeaderRow($matrix, 1, 8);
        $matrix->fromArray([
            ['ITEM'],
            ['1. Understand types of Items available', '', '', 'Yes', '', '', 'Yes', ''],
            ['2. Able to add Item', '', '', 'Yes', '', '', 'Yes', ''],
        ], null, 'A2');
        $matrix->getColumnDimension('A')->setWidth(60);

        $instructions = $spreadsheet->createSheet(2);
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['UAT Tracker — Import Instructions'],
            [''],
            ['The importer accepts either sheet layout. Upload one at a time; the active sheet is the one that is read.'],
            [''],
            ['A. Test Script layout'],
            ['1. Keep the header row exactly as shown: Test Case ID, Screen, Description, Test Steps, Expected Results, Actual Results, Remarks.'],
            ['2. Test Case ID is required and must be unique within the cycle. Rows repeating an existing ID are skipped, never overwritten.'],
            ['3. The text before " - " in Screen becomes the section, so "Issuances - Department Orders" files under Issuances.'],
            ['4. Actual Results accepts Passed, Failed, Blocked, Ongoing, Pending or N/A. It is recorded against a "QA" column.'],
            [''],
            ['B. Walkthrough Matrix layout'],
            ['1. First column must be headed Title. Every column after it is treated as a participant, except a trailing Remark column.'],
            ['2. A row with a title but no verdicts is read as a section banner (e.g. ITEM, BILLING).'],
            ['3. Verdict cells accept Yes / No / Ongoing / N/A as well as Passed / Failed / Blocked / Pending.'],
            ['4. Participants are matched to existing columns by label, and created when they do not exist yet.'],
            [''],
            ['Test Case IDs are generated automatically for matrix rows, following whatever prefix the cycle already uses.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(120);
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instructions->getStyle('A5')->getFont()->setBold(true);
        $instructions->getStyle('A11')->getFont()->setBold(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function styleHeaderRow(Worksheet $sheet, int $row, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function verdictShorthand(string $verdict): string
    {
        return match ($verdict) {
            UatCaseResult::PASSED => 'Yes',
            UatCaseResult::FAILED => 'No',
            UatCaseResult::BLOCKED => 'Blocked',
            UatCaseResult::ONGOING => 'Ongoing',
            UatCaseResult::NOT_APPLICABLE => 'N/A',
            default => '',
        };
    }

    private function verdictColor(string $verdict): string
    {
        return match ($verdict) {
            UatCaseResult::PASSED => 'FF15803D',
            UatCaseResult::FAILED => 'FFB91C1C',
            UatCaseResult::BLOCKED => 'FFB45309',
            UatCaseResult::ONGOING => 'FF1D4ED8',
            default => 'FF6B7280',
        };
    }
}
