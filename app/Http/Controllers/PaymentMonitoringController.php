<?php

namespace App\Http\Controllers;

use App\Models\PaymentConnectivityService;
use App\Models\PaymentInvoice;
use App\Models\PaymentOverpayment;
use App\Models\PaymentRecord;
use App\Models\PaymentRecordApproval;
use App\Models\PaymentRenewal;
use App\Models\PaymentSetting;
use App\Models\PaymentVendor;
use App\Models\PaymentWeeklyPlan;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PaymentMonitoringController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payments.view', only: [
                'index', 'renewals', 'invoices', 'weeklyPlans', 'records', 'invoiceImportTemplate',
                'renewalImportTemplate', 'weeklyPlanImportTemplate', 'connectivityImportTemplate',
            ]),
            new Middleware('can:payments.create', only: [
                'storeRenewal', 'storeInvoice', 'storeWeeklyPlan', 'storeOverpayment', 'importInvoices',
                'importRenewals', 'importWeeklyPlans', 'storeService', 'importConnectivity',
            ]),
            new Middleware('can:payments.edit', only: [
                'updateRenewal', 'updateInvoice', 'updateWeeklyPlan', 'updateService', 'updateLocation', 'syncServices',
            ]),
            new Middleware('can:payments.delete', only: [
                'destroyRenewal', 'destroyInvoice', 'destroyWeeklyPlan', 'destroyOverpayment', 'destroyService',
            ]),
            new Middleware('can:payments.submit', only: ['submitRecord', 'sendManualReminder']),
            new Middleware('can:payments.approve', only: ['approveRecord', 'rejectRecord']),
            new Middleware('can:payments.mark_paid', only: ['markPaid']),
            new Middleware('can:payments.manage_settings', only: ['updateSettings']),
        ];
    }

    public function index(Request $request)
    {
        $tab = $request->get('tab', 'dashboard');

        $shared = [
            'tab' => $tab,
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'code']),
            'stores' => Store::orderBy('code')->get(['id', 'code', 'name']),
            'brands' => Store::query()
                ->whereNotNull('brand')->where('brand', '!=', '')
                ->distinct()->orderBy('brand')->pluck('brand')->values(),
            'companies' => \App\Models\Company::where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'code']),
            'currencies' => ['PHP', 'USD'],
            'cycles' => ['monthly', 'quarterly', 'semi_annual', 'annual'],
            'installTypes' => ['copper', 'fiber', 'wireless'],
            'serviceStatuses' => ['active', 'pending', 'terminated'],
            'telcoOptions' => \App\Models\ReferenceOption::ofType('store_telco'),
            'monitoringStatuses' => ['OPEN', 'PENDING', 'FOR APPLICATION', 'FOR TERMINATION', 'TEMPORARILY CLOSED', 'CLOSED'],
            'invoiceStatuses' => ['Pending', 'Due', 'Overdue', 'Paid', 'Cancelled'],
            'renewalStatuses' => ['active', 'paused', 'cancelled'],
            'weeklyStatuses' => ['Planned', 'Released', 'Paid'],
            'settings' => PaymentSetting::current(),
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
            'summary' => $this->dashboardSummary(),
            'locations' => $this->locationsList($request),
        ];

        // Always load all paginated lists so action POSTs that redirect back
        // refresh the affected table regardless of which tab is active.
        $shared['renewals'] = $this->renewalsList($request);
        $shared['invoices'] = $this->invoicesList($request);
        $shared['overpayments'] = $this->overpaymentsList($request);
        $shared['weeklyPlans'] = $this->weeklyPlansList($request);
        $shared['records'] = $this->recordsList($request);
        $shared['cashSchedule'] = $this->cashSchedule($request);

        return Inertia::render('Payments/Index', $shared);
    }

    public function sendManualReminder(string $type, int $id)
    {
        $settings = PaymentSetting::current();
        $bcc = $settings->global_bcc ? [$settings->global_bcc] : [];
        $ccEmails = [];
        if ($settings->cc_role_id) {
            $role = \Spatie\Permission\Models\Role::find($settings->cc_role_id);
            if ($role) {
                $ccEmails = $role->users()->pluck('email')->filter()->values()->all();
            }
        }

        if ($type === 'invoice') {
            $inv = PaymentInvoice::with('assignee:id,name,email', 'vendor:id,name')->findOrFail($id);
            $due = Carbon::parse($inv->due_date)->startOfDay();
            $payload = [
                'title' => trim(($inv->apv_no ? "APV {$inv->apv_no} " : '') . ($inv->si_number ? "SI {$inv->si_number} " : '') . ($inv->store_code ?? '')),
                'amount' => (float) $inv->outstanding_amount,
                'due_date' => $due->toDateString(),
            ];
            $toEmail = $inv->assignee?->email;
            $rowCc = $this->parseCcEmails($inv->cc_emails);
            $vendorName = $inv->vendor?->name;
        } elseif ($type === 'renewal') {
            $r = PaymentRenewal::with('assignee:id,name,email', 'vendor:id,name')->findOrFail($id);
            $due = Carbon::parse($r->next_due_date)->startOfDay();
            $payload = [
                'title' => trim("{$r->service_type}" . ($r->sub_type ? " — {$r->sub_type}" : '') . ($r->purpose ? " ({$r->purpose})" : '')),
                'amount' => (float) $r->total_amount,
                'due_date' => $due->toDateString(),
            ];
            $toEmail = $r->assignee?->email;
            $rowCc = $this->parseCcEmails($r->cc_emails);
            $vendorName = $r->vendor?->name;
        } elseif ($type === 'service') {
            $svc = PaymentConnectivityService::with('assignee:id,name,email', 'vendor:id,name', 'store:id,code,name')->findOrFail($id);
            $day = $svc->billing_day ?: ($svc->installation_date ? Carbon::parse($svc->installation_date)->day : 1);
            $today = Carbon::now()->startOfDay();
            $due = $today->copy()->day(min($day, $today->daysInMonth));
            if ($due->lt($today)) {
                $next = $today->copy()->addMonthNoOverflow()->startOfMonth();
                $due = $next->copy()->day(min($day, $next->daysInMonth));
            }
            $payload = [
                'title' => trim(($svc->store?->code ? $svc->store->code.' ' : '').($svc->telco ?: 'Telco').' '.ucfirst((string) $svc->role)),
                'amount' => (float) $svc->mrc,
                'due_date' => $due->toDateString(),
            ];
            $toEmail = $svc->assignee?->email;
            $rowCc = $this->parseCcEmails($svc->cc_emails);
            $vendorName = $svc->vendor?->name ?: $svc->telco;
        } else {
            abort(404);
        }

        $mergedCc = array_values(array_filter(array_unique(array_merge($ccEmails, $rowCc))));
        
        $diff = (int) round(Carbon::now()->startOfDay()->diffInDays($due, false));
        $reminderType = $diff < 0 ? 'overdue' : ($diff === 0 ? 'due' : ($diff <= 1 ? '1d' : ($diff <= 7 ? '7d' : '30d')));

        $primaryRecipient = $toEmail ?: ($mergedCc[0] ?? ($bcc[0] ?? null));
        if (! $primaryRecipient) {
            return back()->withErrors(['reminder' => 'No recipient for this reminder. Add an assignee or CC email to the item, or set a Global BCC in Settings.']);
        }

        $mailable = new \App\Mail\PaymentDueReminderMail($type, $payload, $reminderType, $vendorName);
        $pending = \Illuminate\Support\Facades\Mail::to($primaryRecipient);
        if (!empty($mergedCc)) $pending->cc($mergedCc);
        if (!empty($bcc)) $pending->bcc($bcc);
        $pending->send($mailable);

        \App\Models\PaymentReminderLog::create([
            'payable_type' => $type,
            'payable_id' => $id,
            'reminder_type' => $reminderType . ' (manual)',
            'window_date' => Carbon::now()->toDateString(),
            'sent_at' => now(),
            'recipients' => array_values(array_filter(array_unique(array_merge([$toEmail], $mergedCc, $bcc)))),
        ]);

        return back()->with('success', 'Manual reminder sent successfully.');
    }

    protected function parseCcEmails(?string $emails): array
    {
        if (!$emails) return [];
        return array_filter(array_map('trim', explode(',', $emails)));
    }

    protected function dashboardSummary(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $thirtyDays = $now->copy()->addDays(30);

        $outstanding = (float) PaymentInvoice::whereNotIn('status', ['Paid', 'Cancelled'])
            ->sum('outstanding_amount');

        $dueThisMonth = (float) PaymentInvoice::whereNotIn('status', ['Paid', 'Cancelled'])
            ->whereBetween('due_date', [$monthStart, $monthEnd])
            ->sum('outstanding_amount');

        $overdueCount = PaymentInvoice::whereNotIn('status', ['Paid', 'Cancelled'])
            ->whereDate('due_date', '<', $now->toDateString())
            ->count();

        $upcomingRenewals = PaymentRenewal::where('status', 'active')
            ->whereDate('next_due_date', '<=', $thirtyDays->toDateString())
            ->whereDate('next_due_date', '>=', $now->toDateString())
            ->count();

        $annualRenewalSpend = (float) PaymentRenewal::where('status', 'active')
            ->get()
            ->sum(function ($r) {
                $perCycle = (float) $r->total_amount;
                return match ($r->cycle) {
                    'monthly' => $perCycle * 12,
                    'quarterly' => $perCycle * 4,
                    'semi_annual' => $perCycle * 2,
                    'annual' => $perCycle,
                    default => $perCycle * 12,
                };
            });

        $pendingApprovals = PaymentRecord::whereIn('status', ['pending', 'approved'])->count();

        $monthlyMrc = (float) PaymentConnectivityService::where('status', 'active')->sum('mrc');
        $activeServices = PaymentConnectivityService::where('status', 'active')->count();
        $pendingInstalls = PaymentConnectivityService::where('status', 'pending')->count();
        $monitoredLocations = PaymentConnectivityService::distinct('store_id')->count('store_id');

        return [
            'total_outstanding' => $outstanding,
            'due_this_month' => $dueThisMonth,
            'overdue_count' => $overdueCount,
            'upcoming_renewals_30d' => $upcomingRenewals,
            'annual_renewal_spend' => $annualRenewalSpend,
            'pending_approvals' => $pendingApprovals,
            'monthly_mrc' => $monthlyMrc,
            'annual_mrc' => $monthlyMrc * 12,
            'active_services' => $activeServices,
            'pending_installs' => $pendingInstalls,
            'monitored_locations' => $monitoredLocations,
        ];
    }

    /* ============ CONNECTIVITY (Offices / Stores) ============ */

    protected function locationsList(Request $request): array
    {
        $services = PaymentConnectivityService::query()
            ->with(['vendor:id,name,code', 'assignee:id,name'])
            ->addSelect('payment_connectivity_services.*')
            ->addSelect(['latest_record_status' => PaymentRecord::select('status')
                ->whereColumn('payable_id', 'payment_connectivity_services.id')
                ->where('payable_type', 'service')
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->addSelect(['last_reminder_sent_at' => \App\Models\PaymentReminderLog::select('sent_at')
                ->whereColumn('payable_id', 'payment_connectivity_services.id')
                ->where('payable_type', 'service')
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->orderByRaw("CASE WHEN role = 'primary' THEN 0 ELSE 1 END")
            ->get()
            ->groupBy('store_id');

        return Store::query()
            ->orderBy('brand')->orderBy('code')
            ->get([
                'id', 'code', 'name', 'address', 'legal_company', 'company_applied_with',
                'monitoring_status', 'brand', 'class', 'is_active', 'latitude', 'longitude', 'opening_date',
            ])
            ->map(function (Store $store) use ($services) {
                return [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'address' => $store->address,
                    'legal_company' => $store->legal_company,
                    'company_applied_with' => $store->company_applied_with,
                    'monitoring_status' => $store->monitoring_status,
                    'brand' => $store->brand,
                    'class' => $store->class,
                    'is_active' => (bool) $store->is_active,
                    'latitude' => $store->latitude,
                    'longitude' => $store->longitude,
                    'opening_date' => optional($store->opening_date)->toDateString(),
                    'type' => strcasecmp((string) $store->class, 'Office') === 0 ? 'office' : 'store',
                    'services' => ($services[$store->id] ?? collect())->values(),
                ];
            })
            ->values()
            ->all();
    }

    protected function serviceRules(): array
    {
        return [
            'store_id' => 'required|exists:stores,id',
            'role' => 'required|in:primary,secondary',
            'vendor_id' => 'nullable|exists:vendors,id',
            'telco' => 'nullable|string|max:255',
            'account_no' => 'nullable|string|max:255',
            'service_id' => 'nullable|string|max:255',
            'bandwidth' => 'nullable|string|max:100',
            'install_type' => 'nullable|string|max:50',
            'installation_date' => 'nullable|date',
            'billing_day' => 'nullable|integer|min:1|max:31',
            'mrc' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'status' => 'nullable|in:active,pending,terminated',
            'assignee_id' => 'nullable|exists:users,id',
            'cc_emails' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }

    public function storeService(Request $request)
    {
        $data = $request->validate($this->serviceRules());
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['currency'] = $data['currency'] ?? 'PHP';
        $data['status'] = $data['status'] ?? 'active';

        PaymentConnectivityService::create($data);

        return redirect()->back()->with('success', 'Connectivity service added successfully');
    }

    public function updateService(Request $request, PaymentConnectivityService $service)
    {
        if ($this->hasApprovedRecord('service', $service->id)) {
            return redirect()->back()->withErrors(['service' => 'Cannot edit — a payment record for this service is already approved.']);
        }
        $data = $request->validate($this->serviceRules());
        $data['updated_by'] = $request->user()->id;
        $service->update($data);

        return redirect()->back()->with('success', 'Connectivity service updated successfully');
    }

    public function destroyService(PaymentConnectivityService $service)
    {
        $service->delete();

        return redirect()->back()->with('success', 'Connectivity service deleted successfully');
    }

    public function updateLocation(Request $request, Store $store)
    {
        $data = $request->validate([
            'address' => 'nullable|string',
            'legal_company' => 'nullable|string|max:255',
            'company_applied_with' => 'nullable|string|max:255',
            'monitoring_status' => 'nullable|string|max:100',
        ]);
        $store->update($data);

        return redirect()->back()->with('success', 'Location details updated successfully');
    }

    /**
     * Batch upsert/delete the connectivity providers for a single location.
     * Each provider entry carries its own telco-specific fields.
     */
    public function syncServices(Request $request, Store $store)
    {
        $data = $request->validate([
            'services' => 'array',
            'services.*.id' => 'nullable|integer',
            'services.*.role' => 'required|in:primary,secondary',
            'services.*.vendor_id' => 'nullable|exists:vendors,id',
            'services.*.telco' => 'nullable|string|max:255',
            'services.*.account_no' => 'nullable|string|max:255',
            'services.*.service_id' => 'nullable|string|max:255',
            'services.*.bandwidth' => 'nullable|string|max:100',
            'services.*.install_type' => 'nullable|string|max:50',
            'services.*.installation_date' => 'nullable|date',
            'services.*.billing_day' => 'nullable|integer|min:1|max:31',
            'services.*.mrc' => 'required|numeric|min:0',
            'services.*.currency' => 'nullable|string|max:8',
            'services.*.status' => 'nullable|in:active,pending,terminated',
            'services.*.assignee_id' => 'nullable|exists:users,id',
            'services.*.cc_emails' => 'nullable|string',
            'services.*.notes' => 'nullable|string',
            'deleted_ids' => 'array',
            'deleted_ids.*' => 'integer',
        ]);

        $user = $request->user();
        $services = $data['services'] ?? [];
        $deletedIds = $data['deleted_ids'] ?? [];

        // Adding new lines needs create; removing lines needs delete.
        $hasNew = collect($services)->contains(fn ($s) => empty($s['id']));
        if ($hasNew && $user->cannot('payments.create')) {
            return back()->withErrors(['services' => 'You do not have permission to add new providers.']);
        }
        if (! empty($deletedIds) && $user->cannot('payments.delete')) {
            return back()->withErrors(['services' => 'You do not have permission to remove providers.']);
        }

        // Guard: a line with an approved payment can't be edited; one with any payment history can't be removed.
        foreach ($services as $s) {
            if (! empty($s['id']) && $this->hasApprovedRecord('service', (int) $s['id'])) {
                return back()->withErrors(['services' => 'A provider with an approved payment cannot be edited. Resolve its payment first.']);
            }
        }
        foreach ($deletedIds as $id) {
            if (PaymentRecord::where('payable_type', 'service')->where('payable_id', $id)->exists()) {
                return back()->withErrors(['services' => 'A provider with payment history cannot be removed.']);
            }
        }

        DB::transaction(function () use ($services, $deletedIds, $store, $user) {
            if (! empty($deletedIds)) {
                PaymentConnectivityService::where('store_id', $store->id)
                    ->whereIn('id', $deletedIds)
                    ->delete();
            }

            foreach ($services as $s) {
                $installDate = $s['installation_date'] ?? null;
                $billingDay = $s['billing_day'] ?? null;
                if (! $billingDay && $installDate) {
                    $billingDay = (int) Carbon::parse($installDate)->day;
                }

                $payload = [
                    'store_id' => $store->id,
                    'role' => $s['role'],
                    'vendor_id' => $s['vendor_id'] ?? null,
                    'telco' => $s['telco'] ?: null,
                    'account_no' => $s['account_no'] ?: null,
                    'service_id' => $s['service_id'] ?: null,
                    'bandwidth' => $s['bandwidth'] ?: null,
                    'install_type' => $s['install_type'] ?: null,
                    'installation_date' => $installDate,
                    'billing_day' => $billingDay,
                    'mrc' => $s['mrc'] ?? 0,
                    'currency' => $s['currency'] ?: 'PHP',
                    'status' => $s['status'] ?: 'active',
                    'assignee_id' => $s['assignee_id'] ?? null,
                    'cc_emails' => $s['cc_emails'] ?: null,
                    'notes' => $s['notes'] ?: null,
                    'updated_by' => $user->id,
                ];

                if (! empty($s['id'])) {
                    PaymentConnectivityService::where('store_id', $store->id)
                        ->where('id', $s['id'])
                        ->update($payload);
                } else {
                    $payload['created_by'] = $user->id;
                    PaymentConnectivityService::create($payload);
                }
            }
        });

        return back()->with('success', 'Connectivity services saved');
    }

    protected function connectivityImportHeaders(): array
    {
        return [
            // ── Location identity (matches the Location/Stores sheet order) ──
            'Branch Code',
            'Address',
            'Brand',
            'Branch Name',
            'Company',
            'Company Applied With',
            // ── Primary telco ──
            'Currently Installed Primary',
            'Account No',
            'Bandwidth',
            'MRC (VAT inc)',
            // ── Secondary telco ──
            'Secondary Telco',
            'Secondary MRC (VAT inc)',
            // ── Appended extras (optional) ──
            'Monitoring Status',
            'Primary Service ID',
            'Primary Install Type',
            'Primary Installation Date',
            'Primary Billing Day',
            'Primary Currency',
            'Primary Status',
            'Primary Vendor',
            'Primary Assignee Email',
            'Primary CC Emails',
            'Primary Notes',
            'Secondary Account No',
            'Secondary Bandwidth',
            'Secondary Service ID',
            'Secondary Install Type',
            'Secondary Installation Date',
            'Secondary Billing Day',
            'Secondary Currency',
            'Secondary Status',
            'Secondary Vendor',
            'Secondary Assignee Email',
            'Secondary CC Emails',
            'Secondary Notes',
        ];
    }

    public function connectivityImportTemplate()
    {
        $vendors = Vendor::orderBy('name')->get(['name', 'code']);
        $users = User::orderBy('email')->get(['email']);
        $stores = Store::orderBy('code')->get(['code', 'name']);

        $spreadsheet = new Spreadsheet;

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Stores');
        foreach ($stores as $index => $store) {
            $listsSheet->setCellValue('A'.($index + 2), $store->code);
        }
        $listsSheet->setCellValue('B1', 'Roles');
        foreach (['primary', 'secondary'] as $index => $role) {
            $listsSheet->setCellValue('B'.($index + 2), $role);
        }
        $listsSheet->setCellValue('C1', 'Install Types');
        foreach (['copper', 'fiber', 'wireless'] as $index => $type) {
            $listsSheet->setCellValue('C'.($index + 2), $type);
        }
        $listsSheet->setCellValue('D1', 'Statuses');
        foreach (['active', 'pending', 'terminated'] as $index => $status) {
            $listsSheet->setCellValue('D'.($index + 2), $status);
        }
        $listsSheet->setCellValue('E1', 'Currencies');
        foreach (['PHP', 'USD'] as $index => $currency) {
            $listsSheet->setCellValue('E'.($index + 2), $currency);
        }
        $listsSheet->setCellValue('F1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $label = trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name);
            $listsSheet->setCellValue('F'.($index + 2), $label);
        }
        $listsSheet->setCellValue('G1', 'Assignee Emails');
        foreach ($users as $index => $user) {
            $listsSheet->setCellValue('G'.($index + 2), $user->email);
        }
        $listsSheet->setCellValue('H1', 'Monitoring Statuses');
        foreach (['OPEN', 'PENDING', 'FOR APPLICATION', 'FOR TERMINATION', 'TEMPORARILY CLOSED', 'CLOSED'] as $index => $ms) {
            $listsSheet->setCellValue('H'.($index + 2), $ms);
        }

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Connectivity');

        $headers = $this->connectivityImportHeaders();
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $today = now()->toDateString();
        $sampleStore = $stores->first();
        $sampleByHeader = [
            'Branch Code' => $sampleStore?->code ?? 'CBTL-001',
            'Address' => 'Sample address, City',
            'Brand' => 'CBTL',
            'Branch Name' => 'Sample Branch',
            'Company' => 'Seventy 7 Seeds, Inc.',
            'Company Applied With' => 'The Tablegroup Inc',
            'Currently Installed Primary' => 'PLDT',
            'Account No' => '6018360606',
            'Bandwidth' => '50 Mbps',
            'MRC (VAT inc)' => '3080.00',
            'Secondary Telco' => 'Smartlink',
            'Secondary MRC (VAT inc)' => '4256.00',
            'Monitoring Status' => 'OPEN',
            'Primary Install Type' => 'copper',
            'Primary Installation Date' => $today,
            'Primary Currency' => 'PHP',
            'Primary Status' => 'active',
            'Primary Notes' => 'Imported primary line',
            'Secondary Install Type' => 'wireless',
            'Secondary Currency' => 'PHP',
            'Secondary Status' => 'active',
        ];
        $sampleRow = array_map(fn ($h) => $sampleByHeader[$h] ?? '', $headers);
        $sheet->fromArray([$sampleRow], null, 'A2');

        // Resolve the spreadsheet column letter for a given header name.
        $colOf = function (string $name) use ($headers) {
            $idx = array_search($name, $headers, true);
            return $idx === false ? null : Coordinate::stringFromColumnIndex($idx + 1);
        };

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');
        foreach (['Primary Installation Date', 'Secondary Installation Date'] as $dateHeader) {
            if ($c = $colOf($dateHeader)) {
                $sheet->getStyle("{$c}:{$c}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
            }
        }
        foreach (['MRC (VAT inc)', 'Secondary MRC (VAT inc)'] as $mrcHeader) {
            if ($c = $colOf($mrcHeader)) {
                $sheet->getStyle("{$c}:{$c}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            }
        }
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        // Dropdowns (Lists: A=Stores, C=InstallTypes, D=Statuses, E=Currencies, F=Vendors, G=Assignees, H=MonitoringStatuses).
        if ($stores->isNotEmpty() && ($c = $colOf('Branch Code'))) {
            $this->applyListValidation($sheet, $c, sprintf('Lists!$A$2:$A$%d', $stores->count() + 1), false);
        }
        if ($c = $colOf('Monitoring Status')) {
            $this->applyListValidation($sheet, $c, 'Lists!$H$2:$H$7', true);
        }
        foreach (['Primary Install Type', 'Secondary Install Type'] as $h) {
            if ($c = $colOf($h)) $this->applyListValidation($sheet, $c, 'Lists!$C$2:$C$4', true);
        }
        foreach (['Primary Status', 'Secondary Status'] as $h) {
            if ($c = $colOf($h)) $this->applyListValidation($sheet, $c, 'Lists!$D$2:$D$4', true);
        }
        foreach (['Primary Currency', 'Secondary Currency'] as $h) {
            if ($c = $colOf($h)) $this->applyListValidation($sheet, $c, 'Lists!$E$2:$E$3', true);
        }
        if ($vendors->isNotEmpty()) {
            foreach (['Primary Vendor', 'Secondary Vendor'] as $h) {
                if ($c = $colOf($h)) $this->applyListValidation($sheet, $c, sprintf('Lists!$F$2:$F$%d', $vendors->count() + 1), true);
            }
        }
        if ($users->isNotEmpty()) {
            foreach (['Primary Assignee Email', 'Secondary Assignee Email'] as $h) {
                if ($c = $colOf($h)) $this->applyListValidation($sheet, $c, sprintf('Lists!$G$2:$G$%d', $users->count() + 1), true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'connectivity-import-template.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importConnectivity(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        $expectedHeaders = $this->connectivityImportHeaders();

        if (! in_array('Branch Code', $header, true)) {
            return response()->json([
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Template is missing the required "Branch Code" column.'],
            ], 422);
        }

        $headerIndexes = array_flip($header);
        $vendorsByKey = $this->importVendorLookup();
        $usersByEmail = $this->importUserLookup();
        $storesByCode = Store::query()->get(['id', 'code'])
            ->keyBy(fn (Store $store) => mb_strtolower(trim((string) $store->code)));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;
        $userId = $request->user()->id;

        // Upsert a single provider line (primary|secondary) from a flat field map; skips when empty.
        $upsert = function (Store $store, string $role, array $f) use ($vendorsByKey, $usersByEmail, $userId, $rowNum, &$created, &$updated, &$skipped, &$errors) {
            if (($f['telco'] ?? '') === '' && ($f['mrc'] ?? '') === '') {
                return; // nothing supplied for this role
            }

            $vendorId = null;
            if (($f['vendor'] ?? '') !== '') {
                $vendor = $vendorsByKey->get(mb_strtolower(trim($f['vendor'])));
                if (! $vendor) {
                    $errors[] = "Row {$rowNum} ({$role}): vendor '{$f['vendor']}' was not found.";
                    $skipped++;
                    return;
                }
                $vendorId = $vendor->id;
            }

            $assigneeId = null;
            if (($f['assignee'] ?? '') !== '') {
                $assignee = $usersByEmail->get(mb_strtolower($f['assignee']));
                if (! $assignee) {
                    $errors[] = "Row {$rowNum} ({$role}): assignee '{$f['assignee']}' was not found.";
                    $skipped++;
                    return;
                }
                $assigneeId = $assignee->id;
            }

            $payload = [
                'store_id' => $store->id,
                'role' => $role,
                'vendor_id' => $vendorId,
                'telco' => ($f['telco'] ?? '') ?: null,
                'account_no' => ($f['account_no'] ?? '') ?: null,
                'service_id' => ($f['service_id'] ?? '') ?: null,
                'bandwidth' => ($f['bandwidth'] ?? '') ?: null,
                'install_type' => ($f['install_type'] ?? '') ? strtolower($f['install_type']) : null,
                'installation_date' => $this->normalizeImportDate($f['installation_date'] ?? ''),
                'billing_day' => ($f['billing_day'] ?? '') !== '' ? (int) $f['billing_day'] : null,
                'mrc' => $this->normalizeImportNumber($f['mrc'] ?? '', 0),
                'currency' => ($f['currency'] ?? '') ?: 'PHP',
                'status' => ($f['status'] ?? '') ? strtolower($f['status']) : 'active',
                'assignee_id' => $assigneeId,
                'cc_emails' => ($f['cc_emails'] ?? '') ?: null,
                'notes' => ($f['notes'] ?? '') ?: null,
            ];

            $validator = Validator::make($payload, $this->serviceRules());
            if ($validator->fails()) {
                $errors[] = "Row {$rowNum} ({$role}): ".implode(', ', $validator->errors()->all());
                $skipped++;
                return;
            }

            $existing = PaymentConnectivityService::where('store_id', $store->id)
                ->where('role', $role)
                ->first();
            if ($existing) {
                $existing->update([...$payload, 'updated_by' => $userId]);
                $updated++;
            } else {
                PaymentConnectivityService::create([...$payload, 'created_by' => $userId, 'updated_by' => $userId]);
                $created++;
            }
        };

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                continue;
            }

            // Read every known header that is present in the uploaded file (absent columns => '').
            $data = [];
            foreach ($expectedHeaders as $h) {
                $data[$h] = isset($headerIndexes[$h])
                    ? $this->normalizeImportValue($line[$headerIndexes[$h]] ?? null)
                    : '';
            }

            $store = $storesByCode->get(mb_strtolower(trim($data['Branch Code'])));
            if (! $store) {
                $errors[] = "Row {$rowNum}: branch code '{$data['Branch Code']}' was not found.";
                $skipped++;
                continue;
            }

            // Store-level identity — only overwrite when a value is provided.
            $storeMap = [
                'address' => 'Address',
                'brand' => 'Brand',
                'name' => 'Branch Name',
                'legal_company' => 'Company',
                'company_applied_with' => 'Company Applied With',
                'monitoring_status' => 'Monitoring Status',
            ];
            $storeUpdates = [];
            foreach ($storeMap as $col => $h) {
                if (($data[$h] ?? '') !== '') {
                    $storeUpdates[$col] = $data[$h];
                }
            }
            if (! empty($storeUpdates)) {
                $store->fill($storeUpdates)->save();
            }

            $upsert($store, 'primary', [
                'telco' => $data['Currently Installed Primary'],
                'account_no' => $data['Account No'],
                'bandwidth' => $data['Bandwidth'],
                'mrc' => $data['MRC (VAT inc)'],
                'service_id' => $data['Primary Service ID'],
                'install_type' => $data['Primary Install Type'],
                'installation_date' => $data['Primary Installation Date'],
                'billing_day' => $data['Primary Billing Day'],
                'currency' => $data['Primary Currency'],
                'status' => $data['Primary Status'],
                'vendor' => $data['Primary Vendor'],
                'assignee' => $data['Primary Assignee Email'],
                'cc_emails' => $data['Primary CC Emails'],
                'notes' => $data['Primary Notes'],
            ]);

            $upsert($store, 'secondary', [
                'telco' => $data['Secondary Telco'],
                'account_no' => $data['Secondary Account No'],
                'bandwidth' => $data['Secondary Bandwidth'],
                'mrc' => $data['Secondary MRC (VAT inc)'],
                'service_id' => $data['Secondary Service ID'],
                'install_type' => $data['Secondary Install Type'],
                'installation_date' => $data['Secondary Installation Date'],
                'billing_day' => $data['Secondary Billing Day'],
                'currency' => $data['Secondary Currency'],
                'status' => $data['Secondary Status'],
                'vendor' => $data['Secondary Vendor'],
                'assignee' => $data['Secondary Assignee Email'],
                'cc_emails' => $data['Secondary CC Emails'],
                'notes' => $data['Secondary Notes'],
            ]);
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    /* ============ RENEWALS ============ */

    protected function renewalsList(Request $request)
    {
        $query = PaymentRenewal::with(['vendor:id,name,code', 'assignee:id,name'])
            ->addSelect(['latest_record_status' => PaymentRecord::select('status')
                ->whereColumn('payable_id', 'payment_renewals.id')
                ->where('payable_type', 'renewal')
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->addSelect(['last_paid_on' => PaymentRecord::select('paid_on')
                ->whereColumn('payable_id', 'payment_renewals.id')
                ->where('payable_type', 'renewal')
                ->where('status', 'posted')
                ->orderByDesc('paid_on')
                ->limit(1),
            ])
            ->addSelect(['last_reminder_sent_at' => \App\Models\PaymentReminderLog::select('sent_at')
                ->whereColumn('payable_id', 'payment_renewals.id')
                ->where('payable_type', 'renewal')
                ->orderByDesc('id')
                ->limit(1),
            ]);

        if ($s = $request->get('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('service_type', 'like', "%{$s}%")
                  ->orWhere('sub_type', 'like', "%{$s}%")
                  ->orWhere('purpose', 'like', "%{$s}%");
            });
        }
        if ($v = $request->get('vendor_id')) {
            $query->where('vendor_id', $v);
        }
        if ($st = $request->get('status')) {
            $query->where('status', $st);
        }
        if ($c = $request->get('cycle')) {
            $query->where('cycle', $c);
        }

        return $query->orderBy('next_due_date')
            ->paginate($request->get('per_page', 15))
            ->withQueryString();
    }

    public function storeRenewal(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_type' => 'required|string|max:255',
            'sub_type' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'unit_cost' => 'required|numeric|min:0',
            'qty' => 'required|integer|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
            'cycle_anchor_date' => 'nullable|date',
            'next_due_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'assignee_user_id' => 'nullable|exists:users,id',
            'cc_emails' => 'nullable|string',
            'status' => 'nullable|in:active,paused,cancelled',
            'notes' => 'nullable|string',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'active';
        $data['currency'] = $data['currency'] ?? 'PHP';

        PaymentRenewal::create($data);

        return redirect()->back()->with('success', 'Renewal created successfully');
    }

    public function updateRenewal(Request $request, PaymentRenewal $renewal)
    {
        if ($this->hasApprovedRecord('renewal', $renewal->id)) {
            return redirect()->back()->withErrors(['renewal' => 'Cannot edit — a payment record for this renewal is already approved.']);
        }
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_type' => 'required|string|max:255',
            'sub_type' => 'nullable|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'unit_cost' => 'required|numeric|min:0',
            'qty' => 'required|integer|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
            'cycle_anchor_date' => 'nullable|date',
            'next_due_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'assignee_user_id' => 'nullable|exists:users,id',
            'cc_emails' => 'nullable|string',
            'status' => 'nullable|in:active,paused,cancelled',
            'notes' => 'nullable|string',
        ]);
        $data['updated_by'] = $request->user()->id;
        $renewal->update($data);

        return redirect()->back()->with('success', 'Renewal updated successfully');
    }

    public function destroyRenewal(PaymentRenewal $renewal)
    {
        $renewal->delete();
        return redirect()->back()->with('success', 'Renewal deleted successfully');
    }

    /* ============ INVOICES ============ */

    protected function invoicesList(Request $request)
    {
        $query = PaymentInvoice::with(['vendor:id,name,code', 'assignee:id,name'])
            ->addSelect(['latest_record_status' => PaymentRecord::select('status')
                ->whereColumn('payable_id', 'payment_invoices.id')
                ->where('payable_type', 'invoice')
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->addSelect(['last_reminder_sent_at' => \App\Models\PaymentReminderLog::select('sent_at')
                ->whereColumn('payable_id', 'payment_invoices.id')
                ->where('payable_type', 'invoice')
                ->orderByDesc('id')
                ->limit(1),
            ]);

        if ($s = $request->get('inv_search')) {
            $query->where(function ($q) use ($s) {
                $q->where('apv_no', 'like', "%{$s}%")
                  ->orWhere('store_code', 'like', "%{$s}%")
                  ->orWhere('po_number', 'like', "%{$s}%")
                  ->orWhere('si_number', 'like', "%{$s}%");
            });
        }
        if ($v = $request->get('inv_vendor_id')) {
            $query->where('vendor_id', $v);
        }
        if ($st = $request->get('inv_status')) {
            $query->where('status', $st);
        }
        if ($from = $request->get('inv_from')) {
            $query->whereDate('due_date', '>=', $from);
        }
        if ($to = $request->get('inv_to')) {
            $query->whereDate('due_date', '<=', $to);
        }

        return $query->orderByDesc('due_date')
            ->paginate($request->get('per_page', 15), ['*'], 'inv_page')
            ->withQueryString();
    }

    public function storeInvoice(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'apv_no' => 'nullable|string|max:100',
            'store_code' => 'nullable|string|max:100',
            'po_number' => 'nullable|string|max:100',
            'si_number' => 'nullable|string|max:100',
            'si_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'invoice_amount' => 'required|numeric|min:0',
            'outstanding_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'status' => 'nullable|in:Pending,Due,Overdue,Paid,Cancelled',
            'remarks' => 'nullable|string',
            'assignee_user_id' => 'nullable|exists:users,id',
            'cc_emails' => 'nullable|string',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'Pending';
        $data['currency'] = $data['currency'] ?? 'PHP';

        PaymentInvoice::create($data);

        return redirect()->back()->with('success', 'Invoice created successfully');
    }

    public function updateInvoice(Request $request, PaymentInvoice $invoice)
    {
        if ($this->hasApprovedRecord('invoice', $invoice->id)) {
            return redirect()->back()->withErrors(['invoice' => 'Cannot edit — a payment record for this invoice is already approved.']);
        }
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'apv_no' => 'nullable|string|max:100',
            'store_code' => 'nullable|string|max:100',
            'po_number' => 'nullable|string|max:100',
            'si_number' => 'nullable|string|max:100',
            'si_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'invoice_amount' => 'required|numeric|min:0',
            'outstanding_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'status' => 'nullable|in:Pending,Due,Overdue,Paid,Cancelled',
            'remarks' => 'nullable|string',
            'assignee_user_id' => 'nullable|exists:users,id',
            'cc_emails' => 'nullable|string',
        ]);
        $data['updated_by'] = $request->user()->id;
        $invoice->update($data);

        return redirect()->back()->with('success', 'Invoice updated successfully');
    }

    public function destroyInvoice(PaymentInvoice $invoice)
    {
        $invoice->delete();
        return redirect()->back()->with('success', 'Invoice deleted successfully');
    }

    public function invoiceImportTemplate()
    {
        $vendors = Vendor::orderBy('name')->get(['name', 'code']);
        $users = User::orderBy('email')->get(['email']);

        $spreadsheet = new Spreadsheet;

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $label = trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name);
            $listsSheet->setCellValue('A'.($index + 2), $label);
        }

        $listsSheet->setCellValue('B1', 'Currencies');
        foreach (['PHP', 'USD'] as $index => $currency) {
            $listsSheet->setCellValue('B'.($index + 2), $currency);
        }

        $listsSheet->setCellValue('C1', 'Statuses');
        foreach (['Pending', 'Due', 'Overdue', 'Paid', 'Cancelled'] as $index => $status) {
            $listsSheet->setCellValue('C'.($index + 2), $status);
        }

        $listsSheet->setCellValue('D1', 'Assignee Emails');
        foreach ($users as $index => $user) {
            $listsSheet->setCellValue('D'.($index + 2), $user->email);
        }

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('SOA Invoices');

        $headers = $this->invoiceImportHeaders();
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $today = now()->toDateString();
        $sampleVendor = $vendors->first();
        $sheet->fromArray([[
            $sampleVendor ? trim(($sampleVendor->code ? "{$sampleVendor->code} - " : '').$sampleVendor->name) : '',
            'APV-001',
            'STORE-001',
            'PO-001',
            'SI-001',
            $today,
            now()->addDays(15)->toDateString(),
            '10000.00',
            '',
            'PHP',
            'Pending',
            'Sample unpaid invoice',
            $users->first()?->email ?? '',
            '',
            '',
            '',
            '',
            '',
        ], [
            $sampleVendor ? trim(($sampleVendor->code ? "{$sampleVendor->code} - " : '').$sampleVendor->name) : '',
            'APV-002',
            'STORE-002',
            'PO-002',
            'SI-002',
            $today,
            $today,
            '10000.00',
            '',
            'PHP',
            'Due',
            'Sample partially paid invoice',
            '',
            '',
            $today,
            '2500.00',
            'REF-001',
            'Partial payment',
        ]], null, 'A2');

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');
        $sheet->getStyle('F:G')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('O:O')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('H:I')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('P:P')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        if ($vendors->isNotEmpty()) {
            $this->applyListValidation($sheet, 'A', sprintf('Lists!$A$2:$A$%d', $vendors->count() + 1), false);
        }
        $this->applyListValidation($sheet, 'J', 'Lists!$B$2:$B$3', true);
        $this->applyListValidation($sheet, 'K', 'Lists!$C$2:$C$6', true);
        if ($users->isNotEmpty()) {
            $this->applyListValidation($sheet, 'M', sprintf('Lists!$D$2:$D$%d', $users->count() + 1), true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'soa-invoices-import-template.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importInvoices(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        $expectedHeaders = $this->invoiceImportHeaders();
        $missingHeaders = array_values(array_diff($expectedHeaders, $header));

        if (! empty($missingHeaders)) {
            return response()->json([
                'created' => 0,
                'updated' => 0,
                'payments_created' => 0,
                'skipped' => 0,
                'errors' => ['Template is missing required columns: '.implode(', ', $missingHeaders)],
            ], 422);
        }

        $headerIndexes = array_flip($header);
        $vendors = Vendor::query()->get(['id', 'name', 'code']);
        $vendorsByKey = collect();
        foreach ($vendors as $vendor) {
            foreach (array_filter([$vendor->name, $vendor->code, trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name)]) as $key) {
                $vendorsByKey->put(mb_strtolower(trim($key)), $vendor);
            }
        }
        $usersByEmail = User::query()
            ->whereNotNull('email')
            ->get(['id', 'email'])
            ->keyBy(fn (User $user) => mb_strtolower(trim($user->email)));

        $created = 0;
        $updated = 0;
        $paymentsCreated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;
        $userId = $request->user()->id;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                continue;
            }

            $data = [];
            foreach ($expectedHeaders as $expectedHeader) {
                $data[$expectedHeader] = $this->normalizeImportValue($line[$headerIndexes[$expectedHeader]] ?? null);
            }

            $vendorKey = mb_strtolower(trim($data['vendor_code_or_name']));
            $vendor = $vendorsByKey->get($vendorKey);
            if (! $vendor) {
                $errors[] = "Row {$rowNum}: vendor '{$data['vendor_code_or_name']}' was not found.";
                $skipped++;
                continue;
            }

            $assignee = null;
            if ($data['assignee_email'] !== '') {
                $assignee = $usersByEmail->get(mb_strtolower($data['assignee_email']));
                if (! $assignee) {
                    $errors[] = "Row {$rowNum}: assignee email '{$data['assignee_email']}' was not found.";
                    $skipped++;
                    continue;
                }
            }

            $invoiceAmount = $this->normalizeImportNumber($data['invoice_amount'], null);
            $paidAmount = $this->normalizeImportNumber($data['paid_amount'], null);
            $hasPayment = $paidAmount !== null
                || $data['paid_on'] !== ''
                || $data['payment_reference_no'] !== ''
                || strcasecmp($data['status'], 'Paid') === 0;
            if ($hasPayment && $paidAmount === null) {
                $paidAmount = $invoiceAmount;
            }

            $outstandingAmount = $this->normalizeImportNumber($data['outstanding_amount'], null);
            if ($outstandingAmount === null && $invoiceAmount !== null) {
                $outstandingAmount = $hasPayment ? max(0, (float) $invoiceAmount - (float) $paidAmount) : $invoiceAmount;
            }

            $payload = [
                'vendor_id' => $vendor->id,
                'apv_no' => $data['apv_no'] ?: null,
                'store_code' => $data['store_code'] ?: null,
                'po_number' => $data['po_number'] ?: null,
                'si_number' => $data['si_number'] ?: null,
                'si_date' => $this->normalizeImportDate($data['si_date']),
                'due_date' => $this->normalizeImportDate($data['due_date']),
                'invoice_amount' => $invoiceAmount,
                'outstanding_amount' => $outstandingAmount,
                'currency' => $data['currency'] ?: 'PHP',
                'status' => $data['status'] ?: 'Pending',
                'remarks' => $data['remarks'] ?: null,
                'assignee_user_id' => $assignee?->id,
                'cc_emails' => $data['cc_emails'] ?: null,
            ];

            if ($hasPayment && (float) $outstandingAmount <= 0) {
                $payload['status'] = 'Paid';
                $payload['outstanding_amount'] = 0;
            }

            $validator = Validator::make($payload, [
                'vendor_id' => 'required|exists:vendors,id',
                'apv_no' => 'nullable|string|max:100',
                'store_code' => 'nullable|string|max:100',
                'po_number' => 'nullable|string|max:100',
                'si_number' => 'nullable|string|max:100',
                'si_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'invoice_amount' => 'required|numeric|min:0',
                'outstanding_amount' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|max:8',
                'status' => 'nullable|in:Pending,Due,Overdue,Paid,Cancelled',
                'remarks' => 'nullable|string',
                'assignee_user_id' => 'nullable|exists:users,id',
                'cc_emails' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: ".implode(', ', $validator->errors()->all());
                $skipped++;
                continue;
            }

            if ($hasPayment) {
                $paymentValidator = Validator::make([
                    'paid_on' => $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString(),
                    'paid_amount' => $paidAmount,
                    'payment_reference_no' => $data['payment_reference_no'] ?: null,
                    'payment_remarks' => $data['payment_remarks'] ?: null,
                ], [
                    'paid_on' => 'required|date',
                    'paid_amount' => 'required|numeric|min:0.01',
                    'payment_reference_no' => 'nullable|string|max:100',
                    'payment_remarks' => 'nullable|string',
                ]);

                if ($paymentValidator->fails()) {
                    $errors[] = "Row {$rowNum}: ".implode(', ', $paymentValidator->errors()->all());
                    $skipped++;
                    continue;
                }
            }

            DB::transaction(function () use (
                $validator,
                $data,
                $hasPayment,
                $paidAmount,
                $userId,
                &$created,
                &$updated,
                &$paymentsCreated
            ) {
                $validated = $validator->validated();
                $invoice = $this->findMatchingInvoice($validated['vendor_id'], $validated['apv_no'] ?? null, $validated['si_number'] ?? null);
                $wasExisting = (bool) $invoice;

                if ($invoice) {
                    $invoice->update([...$validated, 'updated_by' => $userId]);
                } else {
                    $invoice = PaymentInvoice::create([...$validated, 'created_by' => $userId, 'updated_by' => $userId]);
                }

                if ($hasPayment) {
                    $paidOn = $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString();
                    $referenceNo = $data['payment_reference_no'] ?: null;
                    $paymentExists = PaymentRecord::where('payable_type', 'invoice')
                        ->where('payable_id', $invoice->id)
                        ->when($referenceNo, fn ($query) => $query->where('reference_no', $referenceNo))
                        ->when(! $referenceNo, fn ($query) => $query->whereDate('paid_on', $paidOn)->where('amount', $paidAmount))
                        ->where('status', 'posted')
                        ->exists();

                    if (! $paymentExists) {
                        PaymentRecord::create([
                            'payable_type' => 'invoice',
                            'payable_id' => $invoice->id,
                            'vendor_id' => $invoice->vendor_id,
                            'amount' => $paidAmount,
                            'paid_on' => $paidOn,
                            'reference_no' => $referenceNo,
                            'paid_by' => $userId,
                            'status' => 'posted',
                            'current_approval_level' => 0,
                            'approver_data' => ['levels' => 0, 'approvers' => []],
                            'remarks' => $data['payment_remarks'] ?: 'Imported SOA payment',
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ]);
                        $paymentsCreated++;
                    }

                    $totalPaid = (float) PaymentRecord::where('payable_type', 'invoice')
                        ->where('payable_id', $invoice->id)
                        ->where('status', 'posted')
                        ->sum('amount');
                    $invoice->outstanding_amount = max(0, (float) $invoice->invoice_amount - $totalPaid);
                    if ((float) $invoice->outstanding_amount <= 0) {
                        $invoice->status = 'Paid';
                    } elseif ($invoice->status === 'Paid') {
                        $invoice->status = 'Pending';
                    }
                    $invoice->updated_by = $userId;
                    $invoice->save();
                }

                $wasExisting ? $updated++ : $created++;
            });
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'payments_created' => $paymentsCreated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    /* ============ RENEWAL IMPORT ============ */

    public function renewalImportTemplate()
    {
        $vendors = Vendor::orderBy('name')->get(['name', 'code']);
        $users = User::orderBy('email')->get(['email']);

        $spreadsheet = new Spreadsheet;

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $label = trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name);
            $listsSheet->setCellValue('A'.($index + 2), $label);
        }

        $listsSheet->setCellValue('B1', 'Currencies');
        foreach (['PHP', 'USD'] as $index => $currency) {
            $listsSheet->setCellValue('B'.($index + 2), $currency);
        }

        $listsSheet->setCellValue('C1', 'Cycles');
        foreach (['monthly', 'quarterly', 'semi_annual', 'annual'] as $index => $cycle) {
            $listsSheet->setCellValue('C'.($index + 2), $cycle);
        }

        $listsSheet->setCellValue('D1', 'Statuses');
        foreach (['active', 'paused', 'cancelled'] as $index => $status) {
            $listsSheet->setCellValue('D'.($index + 2), $status);
        }

        $listsSheet->setCellValue('E1', 'Assignee Emails');
        foreach ($users as $index => $user) {
            $listsSheet->setCellValue('E'.($index + 2), $user->email);
        }

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Renewals');

        $headers = $this->renewalImportHeaders();
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $today = now()->toDateString();
        $sampleVendor = $vendors->first();
        $vendorLabel = $sampleVendor ? trim(($sampleVendor->code ? "{$sampleVendor->code} - " : '').$sampleVendor->name) : '';
        $sheet->fromArray([[
            $vendorLabel,
            'Internet Lease Line',
            'Fiber 100Mbps',
            'Branch connectivity',
            '5000.00',
            '1',
            '5000.00',
            'PHP',
            'monthly',
            $today,
            now()->addMonth()->toDateString(),
            now()->addYear()->toDateString(),
            'Net 30',
            'active',
            $users->first()?->email ?? '',
            '',
            'Sample active renewal',
            '',
            '',
            '',
            '',
        ], [
            $vendorLabel,
            'Antivirus License',
            '50 seats',
            'Endpoint security',
            '12000.00',
            '1',
            '12000.00',
            'PHP',
            'annual',
            $today,
            now()->addYear()->toDateString(),
            now()->addYear()->toDateString(),
            'Net 15',
            'active',
            '',
            '',
            'Sample renewal with a recorded payment',
            $today,
            '12000.00',
            'REF-RNW-001',
            'Imported renewal payment',
        ]], null, 'A2');

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');
        $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('G:G')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('J:L')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('R:R')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('S:S')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        if ($vendors->isNotEmpty()) {
            $this->applyListValidation($sheet, 'A', sprintf('Lists!$A$2:$A$%d', $vendors->count() + 1), false);
        }
        $this->applyListValidation($sheet, 'H', 'Lists!$B$2:$B$3', true);
        $this->applyListValidation($sheet, 'I', 'Lists!$C$2:$C$5', false);
        $this->applyListValidation($sheet, 'N', 'Lists!$D$2:$D$4', true);
        if ($users->isNotEmpty()) {
            $this->applyListValidation($sheet, 'O', sprintf('Lists!$E$2:$E$%d', $users->count() + 1), true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'payment-renewals-import-template.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importRenewals(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        $expectedHeaders = $this->renewalImportHeaders();
        $missingHeaders = array_values(array_diff($expectedHeaders, $header));

        if (! empty($missingHeaders)) {
            return response()->json([
                'created' => 0,
                'duplicates' => 0,
                'payments_created' => 0,
                'skipped' => 0,
                'errors' => ['Template is missing required columns: '.implode(', ', $missingHeaders)],
            ], 422);
        }

        $headerIndexes = array_flip($header);
        $vendorsByKey = $this->importVendorLookup();
        $usersByEmail = $this->importUserLookup();
        $userId = $request->user()->id;

        $existingKeys = [];
        foreach (PaymentRenewal::query()->get(['vendor_id', 'service_type', 'sub_type']) as $renewal) {
            $existingKeys[$this->renewalDupKey($renewal->vendor_id, $renewal->service_type, $renewal->sub_type)] = true;
        }

        $created = 0;
        $duplicates = 0;
        $paymentsCreated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $line) {
            $rowNum++;
            if (! array_filter($line, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $data = [];
            foreach ($expectedHeaders as $expectedHeader) {
                $data[$expectedHeader] = $this->normalizeImportValue($line[$headerIndexes[$expectedHeader]] ?? null);
            }

            if ($data['vendor_code_or_name'] === '') {
                $errors[] = "Row {$rowNum}: vendor is required.";
                $skipped++;
                continue;
            }
            $vendor = $vendorsByKey->get(mb_strtolower($data['vendor_code_or_name']));
            if (! $vendor) {
                $errors[] = "Row {$rowNum}: vendor '{$data['vendor_code_or_name']}' was not found.";
                $skipped++;
                continue;
            }
            if ($data['service_type'] === '') {
                $errors[] = "Row {$rowNum}: service_type is required.";
                $skipped++;
                continue;
            }

            $assignee = null;
            if ($data['assignee_email'] !== '') {
                $assignee = $usersByEmail->get(mb_strtolower($data['assignee_email']));
                if (! $assignee) {
                    $errors[] = "Row {$rowNum}: assignee email '{$data['assignee_email']}' was not found.";
                    $skipped++;
                    continue;
                }
            }

            $dupKey = $this->renewalDupKey($vendor->id, $data['service_type'], $data['sub_type']);
            if (isset($existingKeys[$dupKey])) {
                $duplicates++;
                continue;
            }

            $unitCost = $this->normalizeImportNumber($data['unit_cost'], 0);
            $qty = $this->normalizeImportNumber($data['qty'], 1);
            $totalAmount = $this->normalizeImportNumber($data['total_amount'], null);

            $payload = [
                'vendor_id' => $vendor->id,
                'service_type' => $data['service_type'],
                'sub_type' => $data['sub_type'] ?: null,
                'purpose' => $data['purpose'] ?: null,
                'unit_cost' => $unitCost,
                'qty' => $qty,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?: 'PHP',
                'cycle' => mb_strtolower($data['cycle']) ?: 'monthly',
                'cycle_anchor_date' => $this->normalizeImportDate($data['cycle_anchor_date']),
                'next_due_date' => $this->normalizeImportDate($data['next_due_date']),
                'expiration_date' => $this->normalizeImportDate($data['expiration_date']),
                'payment_terms' => $data['payment_terms'] ?: null,
                'status' => $data['status'] ?: 'active',
                'assignee_user_id' => $assignee?->id,
                'cc_emails' => $data['cc_emails'] ?: null,
                'notes' => $data['notes'] ?: null,
            ];

            $validator = Validator::make($payload, [
                'vendor_id' => 'required|exists:vendors,id',
                'service_type' => 'required|string|max:255',
                'sub_type' => 'nullable|string|max:255',
                'purpose' => 'nullable|string|max:255',
                'unit_cost' => 'required|numeric|min:0',
                'qty' => 'required|integer|min:1',
                'total_amount' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|max:8',
                'cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
                'cycle_anchor_date' => 'nullable|date',
                'next_due_date' => 'nullable|date',
                'expiration_date' => 'nullable|date',
                'payment_terms' => 'nullable|string|max:255',
                'status' => 'nullable|in:active,paused,cancelled',
                'assignee_user_id' => 'nullable|exists:users,id',
                'cc_emails' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: ".implode(', ', $validator->errors()->all());
                $skipped++;
                continue;
            }

            $paidAmount = $this->normalizeImportNumber($data['paid_amount'], null);
            $hasPayment = $paidAmount !== null
                || $data['paid_on'] !== ''
                || $data['payment_reference_no'] !== '';
            if ($hasPayment && $paidAmount === null) {
                $paidAmount = $totalAmount ?? ((float) $unitCost * (int) $qty);
            }

            if ($hasPayment) {
                $paymentValidator = Validator::make([
                    'paid_on' => $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString(),
                    'paid_amount' => $paidAmount,
                    'payment_reference_no' => $data['payment_reference_no'] ?: null,
                ], [
                    'paid_on' => 'required|date',
                    'paid_amount' => 'required|numeric|min:0.01',
                    'payment_reference_no' => 'nullable|string|max:100',
                ]);

                if ($paymentValidator->fails()) {
                    $errors[] = "Row {$rowNum}: ".implode(', ', $paymentValidator->errors()->all());
                    $skipped++;
                    continue;
                }
            }

            DB::transaction(function () use ($validator, $data, $hasPayment, $paidAmount, $userId, &$created, &$paymentsCreated) {
                $renewal = PaymentRenewal::create([...$validator->validated(), 'created_by' => $userId, 'updated_by' => $userId]);

                if ($hasPayment) {
                    PaymentRecord::create([
                        'payable_type' => 'renewal',
                        'payable_id' => $renewal->id,
                        'vendor_id' => $renewal->vendor_id,
                        'amount' => $paidAmount,
                        'paid_on' => $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString(),
                        'reference_no' => $data['payment_reference_no'] ?: null,
                        'paid_by' => $userId,
                        'status' => 'posted',
                        'current_approval_level' => 0,
                        'approver_data' => ['levels' => 0, 'approvers' => []],
                        'remarks' => $data['payment_remarks'] ?: 'Imported renewal payment',
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                    $paymentsCreated++;
                }

                $created++;
            });

            $existingKeys[$dupKey] = true;
        }

        return response()->json([
            'created' => $created,
            'duplicates' => $duplicates,
            'payments_created' => $paymentsCreated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    /* ============ WEEKLY PLAN IMPORT ============ */

    public function weeklyPlanImportTemplate()
    {
        $vendors = Vendor::orderBy('name')->get(['name', 'code']);
        $users = User::orderBy('email')->get(['email']);

        $spreadsheet = new Spreadsheet;

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $label = trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name);
            $listsSheet->setCellValue('A'.($index + 2), $label);
        }

        $listsSheet->setCellValue('B1', 'Categories');
        foreach (['POS', 'CCTV', 'Internet', 'Speaker', 'Anti-virus', 'Router', 'Google'] as $index => $category) {
            $listsSheet->setCellValue('B'.($index + 2), $category);
        }

        $listsSheet->setCellValue('C1', 'Statuses');
        foreach (['Planned', 'Released', 'Paid'] as $index => $status) {
            $listsSheet->setCellValue('C'.($index + 2), $status);
        }

        $listsSheet->setCellValue('D1', 'Assignee Emails');
        foreach ($users as $index => $user) {
            $listsSheet->setCellValue('D'.($index + 2), $user->email);
        }

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Weekly Plans');

        $headers = $this->weeklyPlanImportHeaders();
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $today = now()->toDateString();
        $sampleVendor = $vendors->first();
        $vendorLabel = $sampleVendor ? trim(($sampleVendor->code ? "{$sampleVendor->code} - " : '').$sampleVendor->name) : '';
        $sheet->fromArray([[
            $vendorLabel,
            'Store Rollout Q3',
            now()->format('F'),
            '1',
            $today,
            '15000.00',
            'POS',
            'Planned',
            $users->first()?->email ?? '',
            'Sample planned plan row',
            '',
            '',
            '',
            '',
        ], [
            $vendorLabel,
            'Store Rollout Q3',
            now()->format('F'),
            '2',
            now()->addDays(7)->toDateString(),
            '8000.00',
            'CCTV',
            'Paid',
            '',
            'Sample paid plan row',
            $today,
            '8000.00',
            'REF-WK-001',
            'Imported weekly payment',
        ]], null, 'A2');

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');
        $sheet->getStyle('E:E')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('K:K')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('L:L')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        if ($vendors->isNotEmpty()) {
            $this->applyListValidation($sheet, 'A', sprintf('Lists!$A$2:$A$%d', $vendors->count() + 1), false);
        }
        $this->applyListValidation($sheet, 'G', 'Lists!$B$2:$B$8', true);
        $this->applyListValidation($sheet, 'H', 'Lists!$C$2:$C$4', true);
        if ($users->isNotEmpty()) {
            $this->applyListValidation($sheet, 'I', sprintf('Lists!$D$2:$D$%d', $users->count() + 1), true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'payment-weekly-plans-import-template.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importWeeklyPlans(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        $expectedHeaders = $this->weeklyPlanImportHeaders();
        $missingHeaders = array_values(array_diff($expectedHeaders, $header));

        if (! empty($missingHeaders)) {
            return response()->json([
                'created' => 0,
                'duplicates' => 0,
                'payments_created' => 0,
                'skipped' => 0,
                'errors' => ['Template is missing required columns: '.implode(', ', $missingHeaders)],
            ], 422);
        }

        $headerIndexes = array_flip($header);
        $vendorsByKey = $this->importVendorLookup();
        $usersByEmail = $this->importUserLookup();
        $userId = $request->user()->id;

        $existingKeys = [];
        foreach (PaymentWeeklyPlan::query()->get(['vendor_id', 'month', 'week_no', 'category']) as $plan) {
            $existingKeys[$this->weeklyDupKey($plan->vendor_id, $plan->month, $plan->week_no, $plan->category)] = true;
        }

        $created = 0;
        $duplicates = 0;
        $paymentsCreated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $line) {
            $rowNum++;
            if (! array_filter($line, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $data = [];
            foreach ($expectedHeaders as $expectedHeader) {
                $data[$expectedHeader] = $this->normalizeImportValue($line[$headerIndexes[$expectedHeader]] ?? null);
            }

            if ($data['vendor_code_or_name'] === '') {
                $errors[] = "Row {$rowNum}: vendor is required.";
                $skipped++;
                continue;
            }
            $vendor = $vendorsByKey->get(mb_strtolower($data['vendor_code_or_name']));
            if (! $vendor) {
                $errors[] = "Row {$rowNum}: vendor '{$data['vendor_code_or_name']}' was not found.";
                $skipped++;
                continue;
            }

            $assignee = null;
            if ($data['assignee_email'] !== '') {
                $assignee = $usersByEmail->get(mb_strtolower($data['assignee_email']));
                if (! $assignee) {
                    $errors[] = "Row {$rowNum}: assignee email '{$data['assignee_email']}' was not found.";
                    $skipped++;
                    continue;
                }
            }

            $weekNo = $this->normalizeImportNumber($data['week_no'], null);
            $dupKey = $this->weeklyDupKey($vendor->id, $data['month'], $weekNo, $data['category']);
            if (isset($existingKeys[$dupKey])) {
                $duplicates++;
                continue;
            }

            $amount = $this->normalizeImportNumber($data['amount'], 0);

            $payload = [
                'vendor_id' => $vendor->id,
                'project_label' => $data['project_label'] ?: null,
                'month' => $data['month'] ?: null,
                'week_no' => $weekNo !== null && $weekNo !== '' ? (int) $weekNo : null,
                'week_date' => $this->normalizeImportDate($data['week_date']),
                'amount' => $amount,
                'category' => $data['category'] ?: null,
                'notes' => $data['notes'] ?: null,
                'assignee_user_id' => $assignee?->id,
                'status' => $data['status'] ?: 'Planned',
            ];

            $validator = Validator::make($payload, [
                'vendor_id' => 'required|exists:vendors,id',
                'project_label' => 'nullable|string|max:255',
                'month' => 'nullable|string|max:16',
                'week_no' => 'nullable|integer|min:1|max:53',
                'week_date' => 'nullable|date',
                'amount' => 'required|numeric|min:0',
                'category' => 'nullable|string|max:64',
                'notes' => 'nullable|string',
                'assignee_user_id' => 'nullable|exists:users,id',
                'status' => 'nullable|in:Planned,Released,Paid',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: ".implode(', ', $validator->errors()->all());
                $skipped++;
                continue;
            }

            $paidAmount = $this->normalizeImportNumber($data['paid_amount'], null);
            $hasPayment = $paidAmount !== null
                || $data['paid_on'] !== ''
                || $data['payment_reference_no'] !== ''
                || strcasecmp($data['status'], 'Paid') === 0;
            if ($hasPayment && $paidAmount === null) {
                $paidAmount = $amount;
            }

            if ($hasPayment) {
                $paymentValidator = Validator::make([
                    'paid_on' => $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString(),
                    'paid_amount' => $paidAmount,
                    'payment_reference_no' => $data['payment_reference_no'] ?: null,
                ], [
                    'paid_on' => 'required|date',
                    'paid_amount' => 'required|numeric|min:0.01',
                    'payment_reference_no' => 'nullable|string|max:100',
                ]);

                if ($paymentValidator->fails()) {
                    $errors[] = "Row {$rowNum}: ".implode(', ', $paymentValidator->errors()->all());
                    $skipped++;
                    continue;
                }
            }

            DB::transaction(function () use ($validator, $data, $hasPayment, $paidAmount, $userId, &$created, &$paymentsCreated) {
                $values = $validator->validated();
                if ($hasPayment) {
                    $values['status'] = 'Paid';
                }
                $plan = PaymentWeeklyPlan::create([...$values, 'created_by' => $userId, 'updated_by' => $userId]);

                if ($hasPayment) {
                    PaymentRecord::create([
                        'payable_type' => 'weekly',
                        'payable_id' => $plan->id,
                        'vendor_id' => $plan->vendor_id,
                        'amount' => $paidAmount,
                        'paid_on' => $this->normalizeImportDate($data['paid_on']) ?: now()->toDateString(),
                        'reference_no' => $data['payment_reference_no'] ?: null,
                        'paid_by' => $userId,
                        'status' => 'posted',
                        'current_approval_level' => 0,
                        'approver_data' => ['levels' => 0, 'approvers' => []],
                        'remarks' => $data['payment_remarks'] ?: 'Imported weekly plan payment',
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                    $paymentsCreated++;
                }

                $created++;
            });

            $existingKeys[$dupKey] = true;
        }

        return response()->json([
            'created' => $created,
            'duplicates' => $duplicates,
            'payments_created' => $paymentsCreated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], empty($errors) ? 200 : 422);
    }

    /* ============ OVERPAYMENTS ============ */

    protected function overpaymentsList(Request $request)
    {
        return PaymentOverpayment::with(['vendor:id,name', 'invoice:id,apv_no,si_number'])
            ->orderByDesc('collection_date')
            ->paginate($request->get('per_page', 15), ['*'], 'op_page')
            ->withQueryString();
    }

    public function storeOverpayment(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'collection_date' => 'nullable|date',
            'check_details' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string',
            'applied_to_invoice_id' => 'nullable|exists:payment_invoices,id',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        DB::transaction(function () use ($data) {
            $op = PaymentOverpayment::create($data);
            if (!empty($data['applied_to_invoice_id'])) {
                $inv = PaymentInvoice::find($data['applied_to_invoice_id']);
                if ($inv) {
                    $inv->outstanding_amount = max(0, (float) $inv->outstanding_amount - (float) $data['amount']);
                    if ((float) $inv->outstanding_amount === 0.0) {
                        $inv->status = 'Paid';
                    }
                    $inv->save();
                }
            }
        });

        return redirect()->back()->with('success', 'Overpayment recorded');
    }

    public function destroyOverpayment(PaymentOverpayment $overpayment)
    {
        DB::transaction(function () use ($overpayment) {
            if ($overpayment->applied_to_invoice_id) {
                $inv = PaymentInvoice::find($overpayment->applied_to_invoice_id);
                if ($inv) {
                    $inv->outstanding_amount = (float) $inv->outstanding_amount + (float) $overpayment->amount;
                    if ($inv->status === 'Paid' && $inv->outstanding_amount > 0) {
                        $inv->status = 'Pending';
                    }
                    $inv->save();
                }
            }
            $overpayment->delete();
        });
        return redirect()->back()->with('success', 'Overpayment removed');
    }

    /* ============ WEEKLY PLANS ============ */

    protected function weeklyPlansList(Request $request)
    {
        $query = PaymentWeeklyPlan::with(['vendor:id,name,code', 'assignee:id,name'])
            ->addSelect(['latest_record_status' => PaymentRecord::select('status')
                ->whereColumn('payable_id', 'payment_weekly_plans.id')
                ->where('payable_type', 'weekly')
                ->whereIn('status', ['pending', 'approved'])
                ->orderByDesc('id')
                ->limit(1),
            ]);

        if ($s = $request->get('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('project_label', 'like', "%{$s}%")
                  ->orWhere('month', 'like', "%{$s}%")
                  ->orWhere('category', 'like', "%{$s}%")
                  ->orWhereHas('vendor', function ($vq) use ($s) {
                      $vq->where('name', 'like', "%{$s}%");
                  });
            });
        }
        if ($v = $request->get('wp_vendor_id')) {
            $query->where('vendor_id', $v);
        }
        if ($m = $request->get('wp_month')) {
            $query->where('month', $m);
        }
        if ($c = $request->get('wp_category')) {
            $query->where('category', $c);
        }
        if ($st = $request->get('wp_status')) {
            $query->where('status', $st);
        }

        return $query->orderBy('week_date')
            ->paginate($request->get('per_page', 15), ['*'], 'wp_page')
            ->withQueryString();
    }

    public function storeWeeklyPlan(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'project_label' => 'nullable|string|max:255',
            'month' => 'nullable|string|max:16',
            'week_no' => 'nullable|integer|min:1|max:53',
            'week_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0',
            'category' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'assignee_user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:Planned,Released,Paid',
        ]);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'Planned';

        PaymentWeeklyPlan::create($data);
        return redirect()->back()->with('success', 'Weekly plan added');
    }

    public function updateWeeklyPlan(Request $request, PaymentWeeklyPlan $weekly_plan)
    {
        if ($this->hasApprovedRecord('weekly', $weekly_plan->id)) {
            return redirect()->back()->withErrors(['weekly_plan' => 'Cannot edit — a payment record for this plan row is already approved.']);
        }
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'project_label' => 'nullable|string|max:255',
            'month' => 'nullable|string|max:16',
            'week_no' => 'nullable|integer|min:1|max:53',
            'week_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0',
            'category' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'assignee_user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:Planned,Released,Paid',
        ]);
        $data['updated_by'] = $request->user()->id;
        $weekly_plan->update($data);
        return redirect()->back()->with('success', 'Weekly plan updated');
    }

    public function destroyWeeklyPlan(PaymentWeeklyPlan $weekly_plan)
    {
        $weekly_plan->delete();
        return redirect()->back()->with('success', 'Weekly plan deleted');
    }

    /* ============ PAYMENT RECORDS / APPROVAL ============ */

    protected function recordsList(Request $request)
    {
        $query = PaymentRecord::with(['vendor:id,name', 'payer:id,name', 'approvals.user:id,name']);

        if ($s = $request->get('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('payable_type', 'like', "%{$s}%")
                  ->orWhere('payable_id', 'like', "%{$s}%")
                  ->orWhere('reference_no', 'like', "%{$s}%")
                  ->orWhereHas('vendor', function ($vq) use ($s) {
                      $vq->where('name', 'like', "%{$s}%");
                  });
            });
        }
        if ($st = $request->get('rec_status')) {
            $query->where('status', $st);
        }
        if ($v = $request->get('rec_vendor_id')) {
            $query->where('vendor_id', $v);
        }
        return $query->orderByDesc('id')
            ->paginate($request->get('per_page', 15), ['*'], 'rec_page')
            ->withQueryString();
    }

    public function submitRecord(Request $request)
    {
        $data = $request->validate([
            'payable_type' => 'required|in:renewal,invoice,weekly,service',
            'payable_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string',
        ]);

        $payable = $this->resolvePayable($data['payable_type'], $data['payable_id']);
        if (!$payable) {
            return redirect()->back()->withErrors(['payable_id' => 'Payable not found']);
        }

        if (empty($payable->vendor_id)) {
            return redirect()->back()->withErrors(['payable_id' => 'Assign a telco/vendor to this item before submitting for approval.']);
        }

        $alreadyOpen = PaymentRecord::where('payable_type', $data['payable_type'])
            ->where('payable_id', $data['payable_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if ($alreadyOpen) {
            return redirect()->back()->withErrors(['payable_id' => 'A payment record is already in progress for this item.']);
        }

        if (
            $data['payable_type'] === 'renewal'
            && $this->renewalIsPaidAhead($payable)
        ) {
            return redirect()->back()->withErrors(['payable_id' => 'This renewal is already paid for the current cycle.']);
        }

        $settings = PaymentSetting::current();

        $record = PaymentRecord::create([
            'payable_type' => $data['payable_type'],
            'payable_id' => $data['payable_id'],
            'vendor_id' => $payable->vendor_id,
            'amount' => $data['amount'],
            'status' => 'pending',
            'current_approval_level' => 0,
            'approver_data' => [
                'levels' => (int) ($settings->approval_levels ?? 2),
                'approvers' => $settings->approver_user_ids ?? [],
            ],
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', "Payment record #{$record->id} submitted for approval");
    }

    public function approveRecord(Request $request, PaymentRecord $record)
    {
        $request->validate(['remarks' => 'nullable|string']);
        $user = $request->user();
        $settings = PaymentSetting::current();
        $totalLevels = (int) ($record->approver_data['levels'] ?? $settings->approval_levels ?? 2);

        DB::transaction(function () use ($record, $user, $request, $totalLevels) {
            $nextLevel = (int) $record->current_approval_level + 1;
            PaymentRecordApproval::create([
                'payment_record_id' => $record->id,
                'user_id' => $user->id,
                'level' => $nextLevel,
                'action' => 'approved',
                'remarks' => $request->get('remarks'),
            ]);
            $record->current_approval_level = $nextLevel;
            if ($nextLevel >= $totalLevels) {
                $record->status = 'approved';
            }
            $record->updated_by = $user->id;
            $record->save();
        });

        return redirect()->back()->with('success', 'Payment record approved');
    }

    public function rejectRecord(Request $request, PaymentRecord $record)
    {
        $request->validate(['remarks' => 'required|string']);
        $user = $request->user();

        DB::transaction(function () use ($record, $user, $request) {
            PaymentRecordApproval::create([
                'payment_record_id' => $record->id,
                'user_id' => $user->id,
                'level' => (int) $record->current_approval_level + 1,
                'action' => 'rejected',
                'remarks' => $request->get('remarks'),
            ]);
            $record->status = 'rejected';
            $record->updated_by = $user->id;
            $record->save();
        });

        return redirect()->back()->with('success', 'Payment record rejected');
    }

    public function markPaid(Request $request, PaymentRecord $record)
    {
        if ($record->status !== 'approved') {
            return redirect()->back()->withErrors(['record' => 'Record must be approved before marking paid']);
        }
        $data = $request->validate([
            'paid_on' => 'required|date',
            'reference_no' => 'nullable|string|max:100',
        ]);
        $user = $request->user();

        DB::transaction(function () use ($record, $data, $user) {
            $record->update([
                'status' => 'posted',
                'paid_on' => $data['paid_on'],
                'reference_no' => $data['reference_no'] ?? null,
                'paid_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            // Apply to underlying payable
            if ($record->payable_type === 'invoice') {
                $inv = PaymentInvoice::find($record->payable_id);
                if ($inv) {
                    $inv->outstanding_amount = max(0, (float) $inv->outstanding_amount - (float) $record->amount);
                    if ((float) $inv->outstanding_amount === 0.0) {
                        $inv->status = 'Paid';
                    }
                    $inv->save();
                }
            } elseif ($record->payable_type === 'renewal') {
                $r = PaymentRenewal::find($record->payable_id);
                if ($r) {
                    $r->advanceCycle();
                }
            } elseif ($record->payable_type === 'weekly') {
                $w = PaymentWeeklyPlan::find($record->payable_id);
                if ($w) {
                    $w->status = 'Paid';
                    $w->save();
                }
            }
        });

        return redirect()->back()->with('success', 'Payment posted');
    }

    protected function resolvePayable(string $type, int $id)
    {
        return match ($type) {
            'renewal' => PaymentRenewal::find($id),
            'invoice' => PaymentInvoice::find($id),
            'weekly' => PaymentWeeklyPlan::find($id),
            'service' => PaymentConnectivityService::find($id),
            default => null,
        };
    }

    protected function hasApprovedRecord(string $type, int $id): bool
    {
        return PaymentRecord::where('payable_type', $type)
            ->where('payable_id', $id)
            ->where('status', 'approved')
            ->exists();
    }

    protected function renewalIsPaidAhead(PaymentRenewal $renewal): bool
    {
        if (!$renewal->next_due_date || $renewal->next_due_date->lte(Carbon::now()->startOfDay())) {
            return false;
        }

        return PaymentRecord::where('payable_type', 'renewal')
            ->where('payable_id', $renewal->id)
            ->where('status', 'posted')
            ->exists();
    }

    protected function cashSchedule(Request $request): array
    {
        $selectedMonth = $request->get('cash_month') ?: now()->format('Y-m');
        try {
            $monthStart = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
        } catch (\Throwable) {
            $monthStart = now()->startOfMonth();
            $selectedMonth = $monthStart->format('Y-m');
        }
        $yearStart = $monthStart->copy()->startOfYear();
        $yearEnd = $monthStart->copy()->endOfYear();
        $vendorId = $request->get('cash_vendor_id');
        $source = $request->get('cash_source');
        $includePaid = $request->boolean('cash_include_paid');

        $items = collect();

        if (! $source || $source === 'invoice') {
            PaymentInvoice::with('vendor:id,name,code')
                ->whereBetween('due_date', [$yearStart->toDateString(), $yearEnd->toDateString()])
                ->where('status', '!=', 'Cancelled')
                ->when($vendorId, fn ($query) => $query->where('vendor_id', $vendorId))
                ->when(! $includePaid, fn ($query) => $query
                    ->where('status', '!=', 'Paid')
                    ->where('outstanding_amount', '>', 0))
                ->get()
                ->each(function (PaymentInvoice $invoice) use ($items) {
                    $items->push([
                        'source_type' => 'invoice',
                        'source_id' => $invoice->id,
                        'vendor_id' => $invoice->vendor_id,
                        'vendor_name' => $invoice->vendor?->name,
                        'label' => trim('APV '.($invoice->apv_no ?: '-').' / SI '.($invoice->si_number ?: '-')),
                        'due_date' => optional($invoice->due_date)->toDateString(),
                        'amount' => (float) ($invoice->outstanding_amount ?? $invoice->invoice_amount ?? 0),
                        'status' => $invoice->status,
                        'is_paid' => $invoice->status === 'Paid' || (float) $invoice->outstanding_amount <= 0,
                    ]);
                });
        }

        if (! $source || $source === 'renewal') {
            PaymentRenewal::with('vendor:id,name,code')
                ->whereBetween('next_due_date', [$yearStart->toDateString(), $yearEnd->toDateString()])
                ->where('status', 'active')
                ->when($vendorId, fn ($query) => $query->where('vendor_id', $vendorId))
                ->get()
                ->each(function (PaymentRenewal $renewal) use ($items) {
                    $items->push([
                        'source_type' => 'renewal',
                        'source_id' => $renewal->id,
                        'vendor_id' => $renewal->vendor_id,
                        'vendor_name' => $renewal->vendor?->name,
                        'label' => trim($renewal->service_type.($renewal->sub_type ? ' / '.$renewal->sub_type : '')),
                        'due_date' => optional($renewal->next_due_date)->toDateString(),
                        'amount' => (float) ($renewal->total_amount ?? 0),
                        'status' => $renewal->status,
                        'is_paid' => false,
                    ]);
                });
        }

        if (! $source || $source === 'weekly') {
            PaymentWeeklyPlan::with('vendor:id,name,code')
                ->whereBetween('week_date', [$yearStart->toDateString(), $yearEnd->toDateString()])
                ->when($vendorId, fn ($query) => $query->where('vendor_id', $vendorId))
                ->when(! $includePaid, fn ($query) => $query->where('status', '!=', 'Paid'))
                ->get()
                ->each(function (PaymentWeeklyPlan $plan) use ($items) {
                    $items->push([
                        'source_type' => 'weekly',
                        'source_id' => $plan->id,
                        'vendor_id' => $plan->vendor_id,
                        'vendor_name' => $plan->vendor?->name,
                        'label' => trim(($plan->project_label ?: 'Weekly Plan').($plan->category ? ' / '.$plan->category : '')),
                        'due_date' => optional($plan->week_date)->toDateString(),
                        'amount' => (float) ($plan->amount ?? 0),
                        'status' => $plan->status,
                        'is_paid' => $plan->status === 'Paid',
                    ]);
                });
        }

        if (! $source || $source === 'service') {
            PaymentConnectivityService::with('vendor:id,name,code', 'store:id,code,name')
                ->where('status', 'active')
                ->where('mrc', '>', 0)
                ->when($vendorId, fn ($query) => $query->where('vendor_id', $vendorId))
                ->get()
                ->each(function (PaymentConnectivityService $service) use ($items, $yearStart) {
                    $day = $service->billing_day
                        ?: ($service->installation_date ? Carbon::parse($service->installation_date)->day : 1);
                    for ($m = 0; $m < 12; $m++) {
                        $month = $yearStart->copy()->addMonths($m);
                        $due = $month->copy()->day(min($day, $month->daysInMonth));
                        $items->push([
                            'source_type' => 'service',
                            'source_id' => $service->id,
                            'vendor_id' => $service->vendor_id,
                            'vendor_name' => $service->vendor?->name ?: ($service->telco ?: '—'),
                            'label' => trim(($service->store?->code ? $service->store->code.' · ' : '')
                                .($service->telco ?: 'Telco').' '.ucfirst((string) $service->role)),
                            'due_date' => $due->toDateString(),
                            'amount' => (float) $service->mrc,
                            'status' => $service->status,
                            'is_paid' => false,
                        ]);
                    }
                });
        }

        $items = $items
            ->filter(fn ($item) => ! empty($item['due_date']))
            ->sortBy('due_date')
            ->values();

        $monthly = $items
            ->groupBy(fn ($item) => Carbon::parse($item['due_date'])->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month' => $month,
                'total' => round((float) $group->sum('amount'), 2),
                'count' => $group->count(),
                'invoice_total' => round((float) $group->where('source_type', 'invoice')->sum('amount'), 2),
                'renewal_total' => round((float) $group->where('source_type', 'renewal')->sum('amount'), 2),
                'weekly_total' => round((float) $group->where('source_type', 'weekly')->sum('amount'), 2),
                'service_total' => round((float) $group->where('source_type', 'service')->sum('amount'), 2),
            ])
            ->values();

        $weekly = $items
            ->filter(fn ($item) => str_starts_with($item['due_date'], $selectedMonth))
            ->groupBy(fn ($item) => Carbon::parse($item['due_date'])->format('o-\WW'))
            ->map(function ($group, $week) {
                $date = Carbon::parse($group->first()['due_date']);
                return [
                    'week' => $week,
                    'range' => $date->copy()->startOfWeek()->format('M d').' - '.$date->copy()->endOfWeek()->format('M d'),
                    'total' => round((float) $group->sum('amount'), 2),
                    'count' => $group->count(),
                    'items' => $group->values(),
                ];
            })
            ->values();

        $calendar = $items
            ->filter(fn ($item) => str_starts_with($item['due_date'], $selectedMonth))
            ->groupBy('due_date')
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round((float) $group->sum('amount'), 2),
                'count' => $group->count(),
                'items' => $group->values(),
            ])
            ->values();

        return [
            'filters' => [
                'month' => $selectedMonth,
                'vendor_id' => $vendorId,
                'source' => $source ?: 'all',
                'include_paid' => $includePaid,
            ],
            'items' => $items,
            'monthly' => $monthly,
            'weekly' => $weekly,
            'calendar' => $calendar,
            'total' => round((float) $items->sum('amount'), 2),
        ];
    }

    protected function findMatchingInvoice(int $vendorId, ?string $apvNo, ?string $siNumber): ?PaymentInvoice
    {
        if ($apvNo) {
            $invoice = PaymentInvoice::where('vendor_id', $vendorId)
                ->where('apv_no', $apvNo)
                ->first();
            if ($invoice) {
                return $invoice;
            }
        }

        if ($siNumber) {
            return PaymentInvoice::where('vendor_id', $vendorId)
                ->where('si_number', $siNumber)
                ->first();
        }

        return null;
    }

    protected function invoiceImportHeaders(): array
    {
        return [
            'vendor_code_or_name',
            'apv_no',
            'store_code',
            'po_number',
            'si_number',
            'si_date',
            'due_date',
            'invoice_amount',
            'outstanding_amount',
            'currency',
            'status',
            'remarks',
            'assignee_email',
            'cc_emails',
            'paid_on',
            'paid_amount',
            'payment_reference_no',
            'payment_remarks',
        ];
    }

    protected function renewalImportHeaders(): array
    {
        return [
            'vendor_code_or_name',
            'service_type',
            'sub_type',
            'purpose',
            'unit_cost',
            'qty',
            'total_amount',
            'currency',
            'cycle',
            'cycle_anchor_date',
            'next_due_date',
            'expiration_date',
            'payment_terms',
            'status',
            'assignee_email',
            'cc_emails',
            'notes',
            'paid_on',
            'paid_amount',
            'payment_reference_no',
            'payment_remarks',
        ];
    }

    protected function weeklyPlanImportHeaders(): array
    {
        return [
            'vendor_code_or_name',
            'project_label',
            'month',
            'week_no',
            'week_date',
            'amount',
            'category',
            'status',
            'assignee_email',
            'notes',
            'paid_on',
            'paid_amount',
            'payment_reference_no',
            'payment_remarks',
        ];
    }

    protected function renewalDupKey($vendorId, $serviceType, $subType): string
    {
        return $vendorId.'|'.mb_strtolower(trim((string) $serviceType)).'|'.mb_strtolower(trim((string) $subType));
    }

    protected function weeklyDupKey($vendorId, $month, $weekNo, $category): string
    {
        return $vendorId.'|'.mb_strtolower(trim((string) $month)).'|'.trim((string) $weekNo).'|'.mb_strtolower(trim((string) $category));
    }

    protected function importVendorLookup()
    {
        $map = collect();
        foreach (Vendor::query()->get(['id', 'name', 'code']) as $vendor) {
            foreach (array_filter([$vendor->name, $vendor->code, trim(($vendor->code ? "{$vendor->code} - " : '').$vendor->name)]) as $key) {
                $map->put(mb_strtolower(trim($key)), $vendor);
            }
        }

        return $map;
    }

    protected function importUserLookup()
    {
        return User::query()
            ->whereNotNull('email')
            ->get(['id', 'email'])
            ->keyBy(fn (User $user) => mb_strtolower(trim($user->email)));
    }

    protected function applyListValidation($sheet, string $column, string $formula, bool $allowBlank): void
    {
        foreach (range(2, 1001) as $row) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank($allowBlank)
                ->setShowDropDown(true)
                ->setShowInputMessage(true)
                ->setFormula1($formula);
        }
    }

    protected function normalizeImportValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    protected function normalizeImportDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    protected function normalizeImportNumber($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return str_replace(',', '', trim((string) $value));
    }

    /* ============ SETTINGS ============ */

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'cc_role_id' => 'nullable|integer|exists:roles,id',
            'global_bcc' => 'nullable|string|max:255',
            'default_currency' => 'nullable|string|max:8',
            'approval_levels' => 'nullable|integer|min:1|max:5',
            'approver_user_ids' => 'nullable|array',
            'approver_user_ids.*' => 'integer|exists:users,id',
            'reminders_enabled' => 'nullable|boolean',
        ]);
        $data['updated_by'] = $request->user()->id;

        $settings = PaymentSetting::current();
        $settings->update($data);

        return redirect()->back()->with('success', 'Payment settings updated');
    }
}
