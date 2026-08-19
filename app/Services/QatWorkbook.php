<?php

namespace App\Services;

use App\Models\QatCase;
use App\Models\QatCaseResult;
use App\Models\QatCycle;
use App\Models\QatFinding;
use App\Models\QatSection;
use App\Models\QatSignoff;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * xlsx import/export for QAT cycles.
 *
 * The header vocabulary is deliberately identical to {@see UatWorkbook}'s test
 * script layout, so a workbook exported from either module imports into the other
 * — that interchange is the point of the two modules sharing a case shape.
 *
 * Narrower than the UAT workbook on purpose: it imports the script layout only.
 * The UAT importer additionally understands the walkthrough MATRIX layout, where
 * each department is a column of Yes/No answers, because that is how external
 * stakeholder packs arrive. A QAT cycle is run by internal staff in the app, so
 * verdicts are recorded here rather than typed into a spreadsheet first.
 */
class QatWorkbook
{
    /** Header labels that identify a test-script sheet. Shared with UatWorkbook. */
    private const HEADERS = [
        'test case id' => 'case_key',
        'case id' => 'case_key',
        'section/module' => 'section',
        'section / module' => 'section',
        'section' => 'section',
        'screen' => 'screen',
        'title' => 'title',
        'description' => 'description',
        'test steps' => 'steps',
        'steps' => 'steps',
        'expected results' => 'expected_results',
        'expected result' => 'expected_results',
        'priority' => 'priority',
        'critical' => 'critical',
    ];

    public function __construct(private QatService $qat) {}

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Loads test cases into an existing cycle.
     *
     * @return array{sections:int,cases:int,participants:int,skipped:int,errors:array<int,string>}
     */
    public function import(QatCycle $cycle, string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);

        [$headerIndex, $map] = $this->detectHeader($rows);

        if ($headerIndex === null) {
            return [
                'sections' => 0, 'cases' => 0, 'participants' => 0, 'skipped' => 0,
                'errors' => ['No recognisable header row was found. The sheet needs a "Test Case ID" or "Title" column.'],
            ];
        }

        $created = 0;
        $skipped = 0;
        $sectionsCreated = 0;

        // Existing keys are read once; re-importing a workbook must top up the
        // cycle rather than duplicating every row in it.
        $existingKeys = QatCase::where('qat_cycle_id', $cycle->id)
            ->pluck('case_key')
            ->map(fn ($k) => mb_strtolower(trim((string) $k)))
            ->flip();

        $sectionIds = QatSection::where('qat_cycle_id', $cycle->id)
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($s) => [mb_strtolower(trim($s->name)) => $s->id]);

        $order = (int) QatCase::where('qat_cycle_id', $cycle->id)->max('order');

        DB::transaction(function () use (
            $rows, $headerIndex, $map, $cycle, &$created, &$skipped, &$sectionsCreated,
            &$existingKeys, &$sectionIds, &$order
        ) {
            foreach (array_slice($rows, $headerIndex + 1) as $row) {
                $values = $this->readRow($row, $map);

                $title = trim((string) ($values['title'] ?? ''));
                $caseKey = trim((string) ($values['case_key'] ?? ''));

                // A row with neither a key nor a title is a spacer or a footer.
                if ($title === '' && $caseKey === '') {
                    continue;
                }

                if ($title === '') {
                    // Some packs put the whole description in Screen and leave the
                    // title column empty; fall back rather than dropping the row.
                    $title = trim((string) ($values['screen'] ?? '')) ?: $caseKey;
                }

                if ($caseKey === '') {
                    $caseKey = QatCase::nextKey($cycle->id, QatCase::keyPrefix($cycle->id));
                }

                $normalisedKey = mb_strtolower($caseKey);

                if ($existingKeys->has($normalisedKey)) {
                    $skipped++;

                    continue;
                }

                $sectionId = null;
                $sectionName = trim((string) ($values['section'] ?? ''));

                if ($sectionName === '' && ($values['screen'] ?? '') !== '') {
                    // Older workbooks have no section column; the text before
                    // " - " in Screen is the module in every pack seen so far.
                    $parts = explode(' - ', (string) $values['screen'], 2);
                    $sectionName = count($parts) > 1 ? trim($parts[0]) : '';
                }

                if ($sectionName !== '') {
                    $lookup = mb_strtolower($sectionName);

                    if (! $sectionIds->has($lookup)) {
                        $section = QatSection::create([
                            'qat_cycle_id' => $cycle->id,
                            'name' => $sectionName,
                            'order' => $sectionIds->count() + 1,
                        ]);
                        $sectionIds->put($lookup, $section->id);
                        $sectionsCreated++;
                    }

                    $sectionId = $sectionIds->get($lookup);
                }

                QatCase::create([
                    'qat_cycle_id' => $cycle->id,
                    'qat_section_id' => $sectionId,
                    'case_key' => mb_substr($caseKey, 0, 40),
                    'screen' => $this->clip($values['screen'] ?? null, 255),
                    'title' => mb_substr($title, 0, 255),
                    'description' => $values['description'] ?? null,
                    'steps' => $values['steps'] ?? null,
                    'expected_results' => $values['expected_results'] ?? null,
                    'priority' => $this->priority($values['priority'] ?? null),
                    'is_critical' => $this->critical($values['critical'] ?? null),
                    'order' => ++$order,
                ]);

                $existingKeys->put($normalisedKey, true);
                $created++;
            }
        });

        $this->qat->ensureCells($cycle);

        return [
            'sections' => $sectionsCreated,
            'cases' => $created,
            'participants' => 0,
            'skipped' => $skipped,
            'errors' => [],
        ];
    }

    /** @return array{0:?int,1:array<int,string>} header row index and column map */
    private function detectHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 25) as $index => $row) {
            $map = [];

            foreach ($row as $column => $cell) {
                $label = mb_strtolower(trim((string) $cell));

                if ($label !== '' && isset(self::HEADERS[$label])) {
                    $map[$column] = self::HEADERS[$label];
                }
            }

            // A single stray "Title" cell is not a header row; require enough
            // recognised columns that the match cannot be a coincidence.
            if (count($map) >= 2 && (in_array('case_key', $map, true) || in_array('title', $map, true))) {
                return [$index, $map];
            }
        }

        return [null, []];
    }

    /** @param  array<int,string>  $map */
    private function readRow(array $row, array $map): array
    {
        $values = [];

        foreach ($map as $column => $field) {
            $value = $row[$column] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== null && $value !== '') {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    private function priority(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return array_key_exists($value, QatCase::priorities()) ? $value : 'medium';
    }

    private function critical(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return ! in_array(mb_strtolower(trim((string) $value)), ['no', 'n', 'false', '0', 'non-critical'], true);
    }

    private function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function export(QatCycle $cycle): Spreadsheet
    {
        $cycle->loadMissing(['company:id,name', 'department:id,name', 'qaLead:id,name', 'devLead:id,name']);

        $cases = $cycle->cases()->with('section:id,name')->get();
        $participants = $cycle->participants()->get();
        $results = $cycle->results()->get();
        $columns = $this->qat->columns($participants);

        $book = new Spreadsheet;
        $book->getProperties()->setTitle($cycle->code)->setSubject($cycle->title);

        $this->buildSummarySheet($book->getActiveSheet(), $cycle, $cases, $results, $participants);
        $this->buildCaseSheet($book->createSheet(), $cycle, $cases, $results, $columns);
        $this->buildFindingSheet($book->createSheet(), $cycle);
        $this->buildSignoffSheet($book->createSheet(), $cycle);

        $book->setActiveSheetIndex(1);

        return $book;
    }

    private function buildSummarySheet($sheet, QatCycle $cycle, $cases, $results, $participants): void
    {
        $sheet->setTitle('Summary');

        $stats = $this->qat->statistics($cases, $results, $participants);
        $signoff = $cycle->signoffs()
            ->whereNull('qat_participant_id')
            ->where('stage', QatSignoff::STAGE_MANAGER)
            ->where('is_current', true)
            ->first();

        $rows = [
            ['QAT Cycle', $cycle->code],
            ['Title', $cycle->title],
            ['System', $cycle->system_name],
            ['Environment', $cycle->environment],
            ['Cycle no.', $cycle->cycle_no],
            ['Entity', $cycle->company->name ?? ''],
            ['Department', $cycle->department->name ?? ''],
            ['QA lead', $cycle->qaLead->name ?? ''],
            ['Dev lead', $cycle->devLead->name ?? ''],
            ['Status', QatCycle::statuses()[$cycle->status] ?? $cycle->status],
            [null, null],
            ['Total cases', $stats['total_cases']],
            ['Passed', $stats['passed']],
            ['Failed', $stats['failed']],
            ['Blocked', $stats['blocked']],
            ['Pending', $stats['pending']],
            ['Pass rate', round($stats['pass_rate'] * 100, 1).'%'],
            [null, null],
            ['Manager sign-off', $signoff ? (QatSignoff::results()[$signoff->result] ?? $signoff->result) : 'Not signed off'],
            ['Signed by', $signoff->confirmed_name ?? ''],
            ['Signed on', $signoff?->confirmed_at?->format('Y-m-d H:i') ?? ''],
            ['Remarks', $signoff->remarks ?? ''],
        ];

        $sheet->fromArray($rows, null, 'A1');
        $sheet->getStyle('A1:A'.count($rows))->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    private function buildCaseSheet($sheet, QatCycle $cycle, $cases, $results, array $columns): void
    {
        $sheet->setTitle('Test Script');

        // Header labels match the importer's vocabulary exactly, so an exported
        // sheet re-imports (into either module) without editing.
        $header = ['Test Case ID', 'Section/Module', 'Screen', 'Title', 'Description', 'Test Steps', 'Expected Results', 'Priority', 'Critical'];

        foreach ($columns as $column) {
            $header[] = $column['label'];
        }

        $header[] = 'Overall';

        $sheet->fromArray($header, null, 'A1');
        $this->styleHeader($sheet, 'A1:'.$sheet->getHighestColumn().'1');

        $byCase = $results->groupBy('qat_case_id');
        $row = 2;

        foreach ($cases as $case) {
            $caseResults = $byCase->get($case->id, collect());

            $line = [
                $case->case_key,
                $case->section->name ?? '',
                $case->screen,
                $case->title,
                $case->description,
                $case->steps,
                $case->expected_results,
                QatCase::priorities()[$case->priority] ?? $case->priority,
                $case->is_critical ? 'Yes' : 'No',
            ];

            foreach ($columns as $column) {
                $verdict = $this->qat->columnVerdict($caseResults, $column);
                $line[] = QatCaseResult::results()[$verdict] ?? $verdict;
            }

            $verdict = $this->qat->caseVerdict($caseResults, $columns);
            $line[] = QatCaseResult::results()[$verdict] ?? $verdict;

            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $letter) {
            $sheet->getColumnDimension($letter)->setWidth($letter === 'F' ? 50 : 22);
        }

        $sheet->getStyle('E2:G'.max(2, $row - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
    }

    private function buildFindingSheet($sheet, QatCycle $cycle): void
    {
        $sheet->setTitle('Findings');

        $header = ['Ref', 'Severity', 'Status', 'Title', 'Details', 'Test case', 'Assigned to', 'Ticket', 'Waived', 'Waiver reason'];
        $sheet->fromArray($header, null, 'A1');
        $this->styleHeader($sheet, 'A1:J1');

        $findings = $cycle->findings()
            ->with(['assignee:id,name', 'testCase:id,case_key', 'ticket:id,ticket_key'])
            ->orderBy('reference')
            ->get();

        $row = 2;
        foreach ($findings as $finding) {
            $sheet->fromArray([
                $finding->reference,
                QatFinding::severities()[$finding->severity] ?? $finding->severity,
                QatFinding::statuses()[$finding->status] ?? $finding->status,
                $finding->title,
                $finding->details,
                $finding->testCase->case_key ?? '',
                $finding->assignee->name ?? '',
                $finding->ticket->ticket_key ?? '',
                $finding->isWaived() ? 'Yes' : '',
                $finding->waiver_reason,
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'J') as $letter) {
            $sheet->getColumnDimension($letter)->setWidth($letter === 'E' ? 50 : 20);
        }

        $sheet->getStyle('E2:E'.max(2, $row - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
    }

    private function buildSignoffSheet($sheet, QatCycle $cycle): void
    {
        $sheet->setTitle('Sign-off');

        $header = ['Stage', 'Result', 'Confirmed by', 'Email', 'Date', 'Current', 'Remarks', 'Waiver reason'];
        $sheet->fromArray($header, null, 'A1');
        $this->styleHeader($sheet, 'A1:H1');

        $row = 2;
        foreach ($cycle->signoffs()->orderByDesc('confirmed_at')->get() as $signoff) {
            $sheet->fromArray([
                ucfirst($signoff->stage),
                QatSignoff::results()[$signoff->result] ?? $signoff->result,
                $signoff->confirmed_name,
                $signoff->confirmed_email,
                $signoff->confirmed_at?->format('Y-m-d H:i'),
                $signoff->is_current ? 'Yes' : 'Superseded',
                $signoff->remarks,
                $signoff->waiver_reason,
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'H') as $letter) {
            $sheet->getColumnDimension($letter)->setWidth(22);
        }

        $sheet->freezePane('A2');
    }

    // ------------------------------------------------------------------
    // Template
    // ------------------------------------------------------------------

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Test Script');

        $header = ['Test Case ID', 'Section/Module', 'Screen', 'Title', 'Description', 'Test Steps', 'Expected Results', 'Priority', 'Critical'];
        $sheet->fromArray($header, null, 'A1');
        $this->styleHeader($sheet, 'A1:I1');

        $sheet->fromArray([
            ['TC-01', 'Login', 'Login - Sign in', 'Sign in with valid credentials',
                'Confirm a registered user can sign in.',
                "1. Open the login page\n2. Enter valid credentials\n3. Click Sign in",
                'The dashboard loads and the user name appears in the header.', 'High', 'Yes'],
            ['TC-02', 'Login', 'Login - Sign in', 'Reject an invalid password',
                'Confirm a wrong password is refused.',
                "1. Open the login page\n2. Enter a wrong password\n3. Click Sign in",
                'An error message is shown and no session is created.', 'Medium', 'Yes'],
        ], null, 'A2');

        foreach (range('A', 'I') as $letter) {
            $sheet->getColumnDimension($letter)->setWidth($letter === 'F' ? 50 : 22);
        }
        $sheet->getStyle('E2:G3')->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');

        $notes = $book->createSheet();
        $notes->setTitle('Instructions');
        $notes->fromArray([
            ['QAT import template'],
            [null],
            ['Fill in the Test Script sheet and import it from the QAT cycle page.'],
            [null],
            ['Test Case ID', 'Optional. Left blank, the app allocates the next key in the cycle.'],
            ['Section/Module', 'Groups cases on the matrix. Created automatically if it does not exist.'],
            ['Screen', 'Optional. "Module - Screen" also supplies the section when the section column is blank.'],
            ['Title', 'Required unless Test Case ID is given.'],
            ['Priority', 'Low, Medium, High or Critical. Defaults to Medium.'],
            ['Critical', 'Yes or No. Defaults to Yes. Non-critical cases do not block the sign-off gate.'],
            [null],
            ['Re-importing the same sheet tops the cycle up: rows whose Test Case ID already exists are skipped, never duplicated.'],
            ['This layout is shared with the UAT Tracker, so a script exported from either module imports into the other.'],
        ], null, 'A1');
        $notes->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $notes->getColumnDimension('A')->setWidth(24);
        $notes->getColumnDimension('B')->setWidth(90);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function styleHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFDBFE']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
    }
}
