<?php

namespace App\Http\Controllers;

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
use Inertia\Inertia;

class PaymentMonitoringController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payments.view', only: [
                'index', 'renewals', 'invoices', 'weeklyPlans', 'records',
            ]),
            new Middleware('can:payments.create', only: [
                'storeRenewal', 'storeInvoice', 'storeWeeklyPlan', 'storeOverpayment',
            ]),
            new Middleware('can:payments.edit', only: [
                'updateRenewal', 'updateInvoice', 'updateWeeklyPlan',
            ]),
            new Middleware('can:payments.delete', only: [
                'destroyRenewal', 'destroyInvoice', 'destroyWeeklyPlan', 'destroyOverpayment',
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
            'currencies' => ['PHP', 'USD'],
            'cycles' => ['monthly', 'quarterly', 'semi_annual', 'annual'],
            'invoiceStatuses' => ['Pending', 'Due', 'Overdue', 'Paid', 'Cancelled'],
            'renewalStatuses' => ['active', 'paused', 'cancelled'],
            'weeklyStatuses' => ['Planned', 'Released', 'Paid'],
            'settings' => PaymentSetting::current(),
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
            'summary' => $this->dashboardSummary(),
        ];

        // Always load all paginated lists so action POSTs that redirect back
        // refresh the affected table regardless of which tab is active.
        $shared['renewals'] = $this->renewalsList($request);
        $shared['invoices'] = $this->invoicesList($request);
        $shared['overpayments'] = $this->overpaymentsList($request);
        $shared['weeklyPlans'] = $this->weeklyPlansList($request);
        $shared['records'] = $this->recordsList($request);

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
        } else {
            abort(404);
        }

        $mergedCc = array_values(array_filter(array_unique(array_merge($ccEmails, $rowCc))));
        
        $diff = (int) round(Carbon::now()->startOfDay()->diffInDays($due, false));
        $reminderType = $diff < 0 ? 'overdue' : ($diff === 0 ? 'due' : ($diff <= 1 ? '1d' : ($diff <= 7 ? '7d' : '30d')));

        $mailable = new \App\Mail\PaymentDueReminderMail($type, $payload, $reminderType, $vendorName);
        $pending = $toEmail ? \Illuminate\Support\Facades\Mail::to($toEmail) : \Illuminate\Support\Facades\Mail::to($mergedCc[0] ?? $bcc[0]);
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

        return [
            'total_outstanding' => $outstanding,
            'due_this_month' => $dueThisMonth,
            'overdue_count' => $overdueCount,
            'upcoming_renewals_30d' => $upcomingRenewals,
            'annual_renewal_spend' => $annualRenewalSpend,
            'pending_approvals' => $pendingApprovals,
        ];
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
            'payable_type' => 'required|in:renewal,invoice,weekly',
            'payable_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string',
        ]);

        $payable = $this->resolvePayable($data['payable_type'], $data['payable_id']);
        if (!$payable) {
            return redirect()->back()->withErrors(['payable_id' => 'Payable not found']);
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
