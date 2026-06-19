<?php

namespace App\Http\Controllers;

use App\Models\MallHookup;
use App\Models\MallHookupCost;
use App\Models\MallHookupLog;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MallHookupController extends Controller implements HasMiddleware
{
    /** Daily POS auto-sending status codes. */
    public const STATUSES = ['yes', 'no', 'na', 'for_accreditation'];

    /** Fixed vocabulary of "No" reasons (Autocomplete allows custom too). */
    public const REASONS = [
        'Hardware issue',
        'Improper shutdown',
        'Inconsistent sending',
        'Intermittent internet',
        'Network issue',
        'No internet',
        'POS offline',
        'POS system error',
    ];

    /** Mall hook-up lifecycle states. */
    public const HOOKUP_STATUSES = [
        'Sending',
        'For Accreditation',
        'No Mall Hook-up Requirement',
        'N/A',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:mall_hookup.view', only: ['index', 'export', 'importTemplate']),
            new Middleware('can:mall_hookup.create', only: ['importLogs']),
            new Middleware('can:mall_hookup.edit', only: ['updateHookup', 'saveDailyLogs']),
        ];
    }

    public function index(Request $request)
    {
        $this->ensureHookups();

        $tab = $request->get('tab', 'dashboard');
        $year = (int) $request->get('year', now()->year);
        $date = $request->get('date', now()->toDateString());

        // Matrix date range defaults to the trailing ~6 weeks.
        $matrixTo = $request->get('matrix_to', now()->toDateString());
        $matrixFrom = $request->get('matrix_from', Carbon::parse($matrixTo)->subDays(41)->toDateString());

        return Inertia::render('MallHookup/Index', [
            'tab' => $tab,
            'filters' => [
                'year' => $year,
                'date' => $date,
                'matrix_from' => $matrixFrom,
                'matrix_to' => $matrixTo,
            ],
            'statuses' => self::STATUSES,
            'reasons' => self::REASONS,
            'hookupStatuses' => self::HOOKUP_STATUSES,
            'stores' => Store::orderBy('code')->get(['id', 'code', 'name', 'area', 'brand']),
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
            'years' => $this->availableYears($year),
            'summary' => $this->dashboardSummary(),
            'weeklyReport' => $this->weeklyReport($year),
            'locations' => $this->locationsList(),
            'dailyBoard' => $this->dailyBoard($date),
            'matrix' => $this->matrixData($matrixFrom, $matrixTo),
        ]);
    }

    /**
     * Every active store is monitored — make sure each has a hookup row so the
     * Locations / Daily / Matrix tabs list them without any manual "add" step.
     */
    protected function ensureHookups(): void
    {
        $existing = MallHookup::pluck('store_id')->all();
        $missing = Store::where('is_active', true)
            ->when(! empty($existing), fn ($q) => $q->whereNotIn('id', $existing))
            ->pluck('id');

        if ($missing->isEmpty()) {
            return;
        }

        $now = now();
        MallHookup::insert($missing->map(fn ($id) => [
            'store_id' => $id,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * Savings = baseline (earliest year on record) − current (latest year).
     * Works for any set of years, not a fixed 2024/2025/2026.
     */
    protected function computeSavings($costs): array
    {
        $sorted = collect($costs)->sortBy('year')->values();
        if ($sorted->count() < 1) {
            return ['baseline' => 0.0, 'current' => 0.0, 'savings' => 0.0];
        }
        $baseline = (float) $sorted->first()['amount'];
        $current = (float) $sorted->last()['amount'];

        return ['baseline' => $baseline, 'current' => $current, 'savings' => $baseline - $current];
    }

    /* ============ DASHBOARD ============ */

    protected function dashboardSummary(): array
    {
        $hookups = MallHookup::with('costs:id,mall_hookup_id,year,amount')->get(['id']);
        $cost2024 = 0.0; // baseline total
        $cost2026 = 0.0; // current total
        $savings = 0.0;
        foreach ($hookups as $h) {
            $s = $this->computeSavings($h->costs->map(fn ($c) => ['year' => $c->year, 'amount' => $c->amount]));
            $cost2024 += $s['baseline'];
            $cost2026 += $s['current'];
            $savings += $s['savings'];
        }
        $decrease = $cost2024 > 0 ? round($savings / $cost2024 * 100, 2) : 0.0;

        // Latest day with any logged status → headline KPI numbers.
        $latestDate = MallHookupLog::max('log_date');
        $latest = ['date' => $latestDate, 'yes' => 0, 'no' => 0, 'na' => 0, 'for_accreditation' => 0, 'total' => 0, 'sending_pct' => 0];
        if ($latestDate) {
            $counts = MallHookupLog::whereDate('log_date', $latestDate)
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
            $latest['yes'] = (int) ($counts['yes'] ?? 0);
            $latest['no'] = (int) ($counts['no'] ?? 0);
            $latest['na'] = (int) ($counts['na'] ?? 0);
            $latest['for_accreditation'] = (int) ($counts['for_accreditation'] ?? 0);
            $latest['total'] = $latest['yes'] + $latest['no'] + $latest['na'] + $latest['for_accreditation'];
            $denom = $latest['yes'] + $latest['no'];
            $latest['sending_pct'] = $denom > 0 ? round($latest['yes'] / $denom * 100) : 0;
        }

        return [
            'savings' => $savings,
            'decrease_pct' => $decrease,
            'baseline_cost' => $cost2024,
            'current_cost' => $cost2026,
            'monitored_stores' => $hookups->count(),
            'latest' => $latest,
        ];
    }

    /**
     * Per-day aggregates for a year, bucketed into ISO weeks — drives the
     * "Weekly Status" table + combo chart (stacked Yes/No bars + Sending % line).
     */
    protected function weeklyReport(int $year): array
    {
        $rows = MallHookupLog::query()
            ->whereYear('log_date', $year)
            ->selectRaw('log_date, status, COUNT(*) as c')
            ->groupBy('log_date', 'status')
            ->get();

        // First fold raw rows into per-day tallies.
        $daily = [];
        foreach ($rows as $r) {
            $d = Carbon::parse($r->log_date)->toDateString();
            $daily[$d] ??= ['yes' => 0, 'no' => 0, 'na' => 0, 'for_accreditation' => 0];
            $daily[$d][$r->status] = (int) $r->c;
        }

        // Then bucket days into ISO weeks and average the per-day figures.
        $weeks = [];
        foreach ($daily as $d => $t) {
            $w = (int) Carbon::parse($d)->isoWeek();
            $weeks[$w] ??= ['days' => 0, 'yes' => 0, 'no' => 0, 'na' => 0, 'for_accreditation' => 0, 'total' => 0, 'pct_sum' => 0];
            $total = $t['yes'] + $t['no'] + $t['na'] + $t['for_accreditation'];
            $denom = $t['yes'] + $t['no'];
            $weeks[$w]['days']++;
            $weeks[$w]['yes'] += $t['yes'];
            $weeks[$w]['no'] += $t['no'];
            $weeks[$w]['na'] += $t['na'];
            $weeks[$w]['for_accreditation'] += $t['for_accreditation'];
            $weeks[$w]['total'] += $total;
            $weeks[$w]['pct_sum'] += $denom > 0 ? $t['yes'] / $denom * 100 : 0;
        }

        ksort($weeks);

        $out = [];
        foreach ($weeks as $w => $a) {
            $days = max(1, $a['days']);
            $out[] = [
                'week' => $w,
                // Counts shown in the table are the weekly average per day (matches the source workbook).
                'yes' => (int) round($a['yes'] / $days),
                'no' => (int) round($a['no'] / $days),
                'na' => (int) round($a['na'] / $days),
                'for_accreditation' => (int) round($a['for_accreditation'] / $days),
                'avg_total_pos' => (int) round($a['total'] / $days),
                'avg_sending_pct' => (int) round($a['pct_sum'] / $days),
            ];
        }

        return $out;
    }

    protected function availableYears(int $current): array
    {
        $years = MallHookupLog::selectRaw('DISTINCT YEAR(log_date) as y')->pluck('y')
            ->map(fn ($y) => (int) $y)->all();
        if (! in_array($current, $years, true)) {
            $years[] = $current;
        }
        rsort($years);

        return $years;
    }

    /* ============ LOCATIONS (per-store master) ============ */

    protected function locationsList(): array
    {
        return MallHookup::query()
            ->with([
                'store:id,code,name,area,brand',
                'store.connectivityServices:id,store_id,role,telco,bandwidth,install_type',
                'costs:id,mall_hookup_id,year,amount',
            ])
            ->get()
            ->map(function (MallHookup $h) {
                $services = $h->store?->connectivityServices ?? collect();
                $primary = $services->firstWhere('role', 'primary');
                $secondary = $services->firstWhere('role', 'secondary');
                $costs = $h->costs->sortBy('year')->values()
                    ->map(fn ($c) => ['year' => $c->year, 'amount' => (float) $c->amount])->all();

                return [
                    'id' => $h->id,
                    'store_id' => $h->store_id,
                    'store_code' => $h->store?->code,
                    'store_name' => $h->store?->name,
                    'developer' => $h->developer,
                    'area' => $h->store?->area,            // sourced from the Store record
                    'deployment_date' => optional($h->deployment_date)->toDateString(),
                    'deployment_status' => $h->deployment_status,
                    'hookup_status' => $h->hookup_status,
                    'shouldered_facility' => $h->shouldered_facility,
                    'with_ups' => $h->with_ups,
                    'costs' => $costs,
                    'savings' => $this->computeSavings($costs)['savings'],
                    // Telco context is sourced from Payments connectivity — entered once.
                    'primary_telco' => $primary?->telco,
                    'primary_bandwidth' => $primary?->bandwidth,
                    'wiring_type' => $primary?->install_type,
                    'secondary_telco' => $secondary?->telco,
                ];
            })
            ->sortBy('store_code', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function updateHookup(Request $request, MallHookup $mallHookup)
    {
        $data = $request->validate([
            'developer' => 'nullable|string|max:255',
            'deployment_date' => 'nullable|date',
            'deployment_status' => 'nullable|string|max:100',
            'hookup_status' => 'nullable|string|max:100',
            'shouldered_facility' => 'nullable|string|max:255',
            'with_ups' => 'nullable|boolean',
            'costs' => 'array',
            'costs.*.year' => 'required|integer|min:2000|max:2100',
            'costs.*.amount' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $mallHookup, $request) {
            $mallHookup->update([
                'developer' => $data['developer'] ?? null,
                'deployment_date' => $data['deployment_date'] ?? null,
                'deployment_status' => $data['deployment_status'] ?? null,
                'hookup_status' => $data['hookup_status'] ?? null,
                'shouldered_facility' => $data['shouldered_facility'] ?? null,
                'with_ups' => $data['with_ups'] ?? null,
                'updated_by' => $request->user()->id,
            ]);

            // Replace the cost set with what was submitted (one row per year).
            $years = collect($data['costs'] ?? [])->pluck('year')->all();
            $mallHookup->costs()->when(! empty($years), fn ($q) => $q->whereNotIn('year', $years))
                ->when(empty($years), fn ($q) => $q)->delete();
            foreach ($data['costs'] ?? [] as $c) {
                MallHookupCost::updateOrCreate(
                    ['mall_hookup_id' => $mallHookup->id, 'year' => $c['year']],
                    ['amount' => $c['amount']]
                );
            }
        });

        return redirect()->back()->with('success', 'Mall Hookup location updated.');
    }

    /* ============ DAILY MONITORING ============ */

    protected function dailyBoard(string $date): array
    {
        $logs = MallHookupLog::whereDate('log_date', $date)
            ->get(['mall_hookup_id', 'status', 'remark'])
            ->keyBy('mall_hookup_id');

        return MallHookup::query()
            ->with('store:id,code,name,area')
            ->get()
            ->map(function (MallHookup $h) use ($logs) {
                $log = $logs->get($h->id);

                return [
                    'mall_hookup_id' => $h->id,
                    'store_code' => $h->store?->code,
                    'store_name' => $h->store?->name,
                    'developer' => $h->developer,
                    'area' => $h->store?->area,
                    'hookup_status' => $h->hookup_status,
                    'status' => $log?->status,
                    'remark' => $log?->remark,
                ];
            })
            ->sortBy('store_code', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function saveDailyLogs(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'entries' => 'array',
            'entries.*.mall_hookup_id' => 'required|integer|exists:mall_hookups,id',
            'entries.*.status' => 'nullable|in:'.implode(',', self::STATUSES),
            'entries.*.remark' => 'nullable|string|max:255',
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $userId = $request->user()->id;

        DB::transaction(function () use ($data, $date, $userId) {
            foreach ($data['entries'] ?? [] as $e) {
                // A cleared status removes the row for that store/day.
                if (empty($e['status'])) {
                    MallHookupLog::where('mall_hookup_id', $e['mall_hookup_id'])
                        ->whereDate('log_date', $date)->delete();
                    continue;
                }

                $existing = MallHookupLog::where('mall_hookup_id', $e['mall_hookup_id'])
                    ->whereDate('log_date', $date)->first();
                $payload = [
                    'status' => $e['status'],
                    'remark' => $e['status'] === 'no' ? ($e['remark'] ?: null) : null,
                    'updated_by' => $userId,
                ];
                if ($existing) {
                    $existing->update($payload);
                } else {
                    MallHookupLog::create([
                        'mall_hookup_id' => $e['mall_hookup_id'],
                        'log_date' => $date,
                        'created_by' => $userId,
                        ...$payload,
                    ]);
                }
            }
        });

        return redirect()->back()->with('success', 'Daily statuses saved for '.$date.'.');
    }

    /* ============ COMPLIANCE MATRIX ============ */

    protected function matrixData(string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        // Only days that actually have entries become columns.
        $dates = MallHookupLog::whereBetween('log_date', [$from, $to])
            ->selectRaw('DISTINCT log_date')->orderBy('log_date')
            ->pluck('log_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $logs = MallHookupLog::whereBetween('log_date', [$from, $to])
            ->get(['mall_hookup_id', 'log_date', 'status', 'remark']);

        $byHookup = [];
        foreach ($logs as $l) {
            $byHookup[$l->mall_hookup_id][Carbon::parse($l->log_date)->toDateString()] = [
                'status' => $l->status,
                'remark' => $l->remark,
            ];
        }

        $rows = MallHookup::query()
            ->with('store:id,code,name')
            ->get()
            ->map(fn (MallHookup $h) => [
                'mall_hookup_id' => $h->id,
                'store_code' => $h->store?->code,
                'store_name' => $h->store?->name,
                'cells' => $byHookup[$h->id] ?? [],
            ])
            ->sortBy('store_code', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return ['dates' => $dates, 'rows' => $rows];
    }

    /* ============ IMPORT (history) ============ */

    protected function importHeaders(): array
    {
        return ['Store Code', 'Date', 'Status', 'Remark'];
    }

    public function importTemplate()
    {
        $stores = Store::orderBy('code')->get(['code']);

        $spreadsheet = new Spreadsheet;

        $lists = $spreadsheet->createSheet(1);
        $lists->setTitle('Lists');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $lists->setCellValue('A1', 'Stores');
        foreach ($stores as $i => $s) {
            $lists->setCellValue('A'.($i + 2), $s->code);
        }
        $lists->setCellValue('B1', 'Statuses');
        foreach (['Yes', 'No', 'N/A', 'For Accreditation'] as $i => $st) {
            $lists->setCellValue('B'.($i + 2), $st);
        }
        $lists->setCellValue('C1', 'Reasons');
        foreach (self::REASONS as $i => $r) {
            $lists->setCellValue('C'.($i + 2), $r);
        }

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Daily Status Logs');

        foreach ($this->importHeaders() as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        $sheet->fromArray([
            [$stores->first()->code ?? 'CBTL A30', now()->toDateString(), 'Yes', ''],
            [$stores->first()->code ?? 'CBTL A30', now()->subDay()->toDateString(), 'No', 'No internet'],
        ], null, 'A2');

        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2');
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->freezePane('A2');
        foreach (range(1, 4) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        if ($stores->isNotEmpty()) {
            $this->applyListValidation($sheet, 'A', sprintf('Lists!$A$2:$A$%d', $stores->count() + 1));
        }
        $this->applyListValidation($sheet, 'C', 'Lists!$B$2:$B$5');
        $this->applyListValidation($sheet, 'D', sprintf('Lists!$C$2:$C$%d', count(self::REASONS) + 1));

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mall-hookup-logs-template.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importLogs(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:10240']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($v) => trim((string) $v), array_shift($rows) ?? []);

        if (! in_array('Store Code', $header, true) || ! in_array('Date', $header, true)) {
            return response()->json([
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => ['Template is missing the required "Store Code" / "Date" columns.'],
            ], 422);
        }

        $idx = array_flip($header);
        $hookupsByCode = MallHookup::with('store:id,code')->get()
            ->keyBy(fn ($h) => mb_strtolower(trim((string) $h->store?->code)));

        $statusMap = [
            'yes' => 'yes', 'no' => 'no', 'n/a' => 'na', 'na' => 'na',
            'for accreditation' => 'for_accreditation', 'for_accreditation' => 'for_accreditation',
        ];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;
        $userId = $request->user()->id;

        foreach ($rows as $line) {
            $rowNum++;
            if (empty(array_filter($line, fn ($v) => $v !== null && trim((string) $v) !== ''))) {
                continue;
            }

            $code = mb_strtolower(trim((string) ($line[$idx['Store Code']] ?? '')));
            $hookup = $hookupsByCode->get($code);
            if (! $hookup) {
                $errors[] = "Row {$rowNum}: store '{$line[$idx['Store Code']]}' is not in Mall Hookup monitoring.";
                $skipped++;
                continue;
            }

            $date = $this->normalizeImportDate($line[$idx['Date']] ?? '');
            if (! $date) {
                $errors[] = "Row {$rowNum}: invalid or missing date.";
                $skipped++;
                continue;
            }

            $rawStatus = mb_strtolower(trim((string) ($line[$idx['Status']] ?? '')));
            $status = $statusMap[$rawStatus] ?? null;
            if (! $status) {
                $errors[] = "Row {$rowNum}: invalid status '{$line[$idx['Status']]}'.";
                $skipped++;
                continue;
            }

            $remark = isset($idx['Remark']) ? trim((string) ($line[$idx['Remark']] ?? '')) : '';

            $existing = MallHookupLog::where('mall_hookup_id', $hookup->id)->whereDate('log_date', $date)->first();
            $payload = [
                'status' => $status,
                'remark' => $status === 'no' ? ($remark ?: null) : null,
                'updated_by' => $userId,
            ];
            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                MallHookupLog::create([
                    'mall_hookup_id' => $hookup->id,
                    'log_date' => $date,
                    'created_by' => $userId,
                    ...$payload,
                ]);
                $created++;
            }
        }

        return response()->json([
            'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    /* ============ EXPORT (matrix) ============ */

    public function export(Request $request)
    {
        $to = $request->get('matrix_to', now()->toDateString());
        $from = $request->get('matrix_from', Carbon::parse($to)->subDays(41)->toDateString());
        $matrix = $this->matrixData($from, $to);

        $labelMap = ['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A', 'for_accreditation' => 'For Accreditation'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mall Hookup Matrix');

        $sheet->setCellValue('A1', 'Store');
        $col = 2;
        foreach ($matrix['dates'] as $d) {
            $sheet->setCellValueByColumnAndRow($col, 1, Carbon::parse($d)->format('M. d'));
            $col++;
        }

        $r = 2;
        foreach ($matrix['rows'] as $row) {
            $sheet->setCellValue('A'.$r, $row['store_code']);
            $c = 2;
            foreach ($matrix['dates'] as $d) {
                $cell = $row['cells'][$d] ?? null;
                $val = $cell ? ($labelMap[$cell['status']] ?? $cell['status']) : '';
                if ($cell && $cell['status'] === 'no' && $cell['remark']) {
                    $val .= ' ('.$cell['remark'].')';
                }
                $sheet->setCellValueByColumnAndRow($c, $r, $val);
                $c++;
            }
            $r++;
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($matrix['dates']) + 1);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePaneByColumnAndRow(2, 2);
        $sheet->getColumnDimension('A')->setAutoSize(true);

        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mall-hookup-matrix.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /* ============ helpers ============ */

    protected function applyListValidation(Worksheet $sheet, string $column, string $formula): void
    {
        for ($row = 2; $row <= 2000; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setFormula1($formula);
        }
    }

    protected function normalizeImportDate($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }
        try {
            return Carbon::parse(trim((string) $value))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
