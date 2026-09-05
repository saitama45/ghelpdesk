<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVoucherBatchPdf;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Services\VoucherService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class VoucherController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stamps.redeem', only: ['verify']),
            new Middleware('can:stamps.create', only: ['storeBatch']),
            new Middleware('can:stamps.edit', only: ['updateBatch', 'updateClaimPeriod']),
            new Middleware('can:stamps.redeem', only: ['redeem']),
            new Middleware('can:stamps.approve', only: ['activate', 'suspend', 'resume']),
            new Middleware('can:stamps.cancel', only: ['cancelBatch', 'voidVoucher', 'voidRedemption']),
            new Middleware('can:stamps.export', only: ['requestPdf', 'downloadPdf', 'exportRedemptions']),
        ];
    }

    public static function indexProps(?int $companyId): array
    {
        if (! $companyId) return ['voucherBatches' => [], 'voucherRedemptions' => [], 'voucherSummary' => []];

        $batches = VoucherBatch::with('company:id,name,code,logo')
            ->withCount([
                'vouchers',
                'vouchers as used_count' => fn ($q) => $q->where('status', 'used'),
                'vouchers as void_count' => fn ($q) => $q->where('status', 'void'),
            ])
            ->where('company_id', $companyId)->latest()->get();

        $redemptions = VoucherRedemption::with([
            'voucher:id,voucher_batch_id,code',
            'voucher.batch:id,title,partner_name,company_id,status,claim_starts_on,claim_ends_on',
            'customer:id,name,phone', 'store:id,code,name', 'cashier:id,name', 'cashierVendor:id,name', 'voider:id,name',
        ])->whereHas('voucher.batch', fn ($q) => $q->where('company_id', $companyId))
            ->latest('redeemed_at')->limit(100)->get();

        return [
            'voucherBatches' => $batches,
            'voucherRedemptions' => $redemptions,
            'voucherSummary' => [
                'batches' => $batches->count(),
                'issued' => $batches->sum(fn ($b) => $b->vouchers_count - $b->used_count - $b->void_count),
                'used' => $batches->sum('used_count'),
                'void' => $batches->sum('void_count'),
                'recognized' => VoucherRedemption::whereNull('voided_at')
                    ->whereHas('voucher.batch', fn ($q) => $q->where('company_id', $companyId))
                    ->sum('applied_amount'),
            ],
        ];
    }

    public function storeBatch(Request $request, VoucherService $service)
    {
        $companyId = $this->companyId();
        $data = $this->validateBatch($request, true);
        $logoPath = $request->file('partner_logo')?->store('voucher-logos', 'local');

        try {
            DB::transaction(function () use ($data, $logoPath, $companyId, $request, $service) {
                $batch = VoucherBatch::create(array_merge($data, [
                    'company_id' => $companyId,
                    'partner_logo_path' => $logoPath,
                    'status' => 'draft',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]));
                $service->generateCodes($batch);
            });
        } catch (\Throwable $e) {
            if ($logoPath) Storage::disk('local')->delete($logoPath);
            throw $e;
        }

        return back()->with('success', "Voucher batch created with {$data['quantity']} unique codes.");
    }

    public function updateBatch(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        if ($batch->status !== 'draft') throw ValidationException::withMessages(['title' => 'Only draft batches can be edited.']);
        $data = $this->validateBatch($request, false);
        $oldLogo = null;
        if ($request->hasFile('partner_logo')) {
            $oldLogo = $batch->partner_logo_path;
            $data['partner_logo_path'] = $request->file('partner_logo')->store('voucher-logos', 'local');
        }
        $this->invalidatePdf($batch);
        $batch->update(array_merge($data, ['updated_by' => $request->user()->id]));
        if ($oldLogo) Storage::disk('local')->delete($oldLogo);
        return back()->with('success', 'Voucher batch updated. Regenerate its PDF to include the changes.');
    }

    public function updateClaimPeriod(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        if ($batch->status === 'cancelled') {
            throw ValidationException::withMessages(['claim_starts_on' => 'The claim period of a cancelled batch cannot be changed.']);
        }
        if (in_array($batch->pdf_status, ['queued', 'processing'], true)) {
            throw ValidationException::withMessages(['claim_starts_on' => 'Wait for the current print PDF to finish before changing the claim period.']);
        }

        $data = $request->validate([
            'claim_starts_on' => 'required|date',
            'claim_ends_on' => 'required|date|after_or_equal:claim_starts_on',
        ]);

        $this->invalidatePdf($batch);
        $batch->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Claim period updated. Prepare a new print PDF with the revised dates.');
    }

    public function activate(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        if (! $batch->claim_starts_on || ! $batch->claim_ends_on) {
            throw ValidationException::withMessages(['claim_starts_on' => 'Enter both claim dates before activation.']);
        }
        if (! in_array($batch->status, ['draft', 'suspended'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or suspended batches can be activated.']);
        }
        $batch->update(['status' => 'active', 'activated_at' => now(), 'activated_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        return back()->with('success', 'Voucher batch activated.');
    }

    public function suspend(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        if ($batch->status !== 'active') throw ValidationException::withMessages(['status' => 'Only an active batch can be suspended.']);
        $batch->update(['status' => 'suspended', 'updated_by' => $request->user()->id]);
        return back()->with('success', 'Voucher batch suspended.');
    }

    public function resume(Request $request, VoucherBatch $batch) { return $this->activate($request, $batch); }

    public function cancelBatch(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        if ($batch->status === 'cancelled') throw ValidationException::withMessages(['reason' => 'This batch is already cancelled.']);
        $batch->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['reason'], 'updated_by' => $request->user()->id]);
        return back()->with('success', 'Voucher batch cancelled.');
    }

    public function verify(Request $request, VoucherService $service)
    {
        $companyId = $this->companyId();
        $data = $request->validate([
            'code' => 'required|string|max:255',
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('class', 'Regular'))],
        ]);
        return response()->json($service->verify($data['code'], $request->user()->id, $data['store_id'] ?? null, $companyId));
    }

    public function redeem(Request $request, VoucherService $service)
    {
        $companyId = $this->companyId();
        $data = $request->validate([
            'code' => 'required|string|max:255',
            'customer_id' => 'nullable|required_without:new_customer_name|exists:customers,id',
            'new_customer_name' => 'nullable|required_without:customer_id|string|max:255',
            'new_customer_phone' => 'nullable|required_without:customer_id|string|max:50',
            'new_customer_email' => 'nullable|email|max:255',
            'store_id' => ['required', Rule::exists('stores', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('class', 'Regular')->where('is_active', true))],
            'receipt_number' => 'required|string|max:100',
            'sale_date' => 'required|date|before_or_equal:today',
            'gross_sale_total' => 'required|numeric|min:0.01',
        ]);
        $redemption = $service->redeem($data, $request->user()->id, $companyId);
        return response()->json(['message' => 'Voucher applied as payment.', 'redemption' => $redemption]);
    }

    public function voidVoucher(Request $request, Voucher $voucher, VoucherService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $service->voidVoucher($voucher, $data['reason'], $request->user()->id, $this->companyId());
        return response()->json(['message' => 'Unused voucher voided.']);
    }

    public function voidRedemption(Request $request, VoucherRedemption $redemption, VoucherService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $service->voidRedemption($redemption, $data['reason'], $request->user()->id, $this->companyId());
        return response()->json(['message' => 'Redemption voided and voucher restored.']);
    }

    public function requestPdf(Request $request, VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        if (in_array($batch->pdf_status, ['queued', 'processing'], true) && ! $batch->pdf_is_stale) {
            throw ValidationException::withMessages(['pdf' => 'This voucher PDF is already being generated.']);
        }
        $batch->update(['pdf_status' => 'queued', 'pdf_requested_by' => $request->user()->id]);

        // Generate inline so this feature works even when the installation is run
        // with `php artisan serve` and has no separate queue worker. Large batches
        // previously sat in the database forever with zero attempts.
        $job = new GenerateVoucherBatchPdf($batch->id, $request->user()->id);
        try {
            $job->handle();
        } catch (Throwable $exception) {
            $job->failed($exception);
            report($exception);

            return back()->with('error', 'The voucher PDF could not be generated. Please try again.');
        }

        return back()->with('success', 'Voucher PDF is ready to open and print.');
    }

    public function downloadPdf(VoucherBatch $batch)
    {
        $this->guardBatch($batch);
        abort_unless($batch->pdf_status === 'ready' && $batch->pdf_path && Storage::disk('local')->exists($batch->pdf_path), 404);
        return Storage::disk('local')->response(
            $batch->pdf_path,
            'vouchers-batch-'.$batch->id.'.pdf',
            ['Cache-Control' => 'no-store'],
            'inline'
        );
    }

    public function exportRedemptions()
    {
        $companyId = $this->companyId();
        $rows = VoucherRedemption::with(['voucher.batch', 'customer', 'store', 'cashier', 'cashierVendor', 'voider'])
            ->whereHas('voucher.batch', fn ($q) => $q->where('company_id', $companyId))->orderBy('id')->cursor();
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Voucher Code', 'Batch', 'Partner', 'Face Value', 'Applied', 'Forfeited', 'Customer', 'Mobile', 'Store', 'Receipt', 'Sale Date', 'Cashier', 'Redeemed At', 'Status', 'Void Reason']);
            foreach ($rows as $r) fputcsv($out, [
                $r->voucher->code, $r->voucher->batch->title, $r->voucher->batch->partner_name, $r->voucher->batch->face_value,
                $r->applied_amount, $r->forfeited_amount, $r->customer->name, $r->customer->phone, $r->store->code,
                $r->receipt_number, $r->sale_date?->format('Y-m-d'), $r->cashier?->name ?? $r->cashierVendor?->name, $r->redeemed_at?->toIso8601String(),
                $r->voided_at ? 'Voided' : 'Used', $r->void_reason,
            ]);
            fclose($out);
        }, 'voucher-redemptions-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function validateBatch(Request $request, bool $creating): array
    {
        return $request->validate([
            'partner_name' => 'required|string|max:255', 'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'quantity' => $creating ? 'required|integer|min:1|max:10000' : 'prohibited',
            'face_value' => $creating ? 'required|numeric|min:0.01|max:9999999999.99' : 'prohibited',
            'turnover_date' => 'nullable|date', 'claim_starts_on' => 'nullable|date',
            'claim_ends_on' => 'nullable|date|after_or_equal:claim_starts_on',
            'claim_instructions' => 'nullable|string|max:255', 'short_terms' => 'nullable|string|max:500',
            'partner_logo' => 'nullable|image|max:2048',
        ]);
    }

    private function companyId(): int
    {
        $id = CompanyContext::activeCompanyId();
        abort_unless($id, 422, 'Select an active entity first.');
        return $id;
    }

    private function guardBatch(VoucherBatch $batch): void { abort_unless((int) $batch->company_id === $this->companyId(), 404); }

    private function invalidatePdf(VoucherBatch $batch): void
    {
        if ($batch->pdf_path) Storage::disk('local')->delete($batch->pdf_path);
        $batch->update(['pdf_status' => 'not_generated', 'pdf_path' => null, 'pdf_generated_at' => null]);
    }
}
