<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\VoucherBatch;
use App\Notifications\ActivityNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\DNS1D;
use Throwable;

class GenerateVoucherBatchPdf implements ShouldQueue
{
    use Queueable;

    public $timeout = 300;

    /** Do not leave the batch in "processing" if rendering reaches the timeout. */
    public $failOnTimeout = true;

    public function __construct(public int $batchId, public int $requesterId)
    {
        $this->onQueue('voucher-pdfs');
    }

    public function handle(): void
    {
        $batch = VoucherBatch::with(['company', 'vouchers' => fn ($q) => $q->orderBy('id')])->findOrFail($this->batchId);

        // A request may have completed this batch while an older duplicate queue
        // record was still waiting. Do not render and notify a second time.
        if ($batch->pdf_status === 'ready' && $batch->pdf_path && Storage::disk('local')->exists($batch->pdf_path)) {
            return;
        }

        $batch->update(['pdf_status' => 'processing']);
        $barcode = new DNS1D;
        $vouchers = $batch->vouchers->map(fn ($voucher) => [
            'voucher' => $voucher,
            'barcode' => 'data:image/png;base64,'.$barcode->getBarcodePNG($voucher->code, 'C128', 1.35, 38),
        ]);

        $content = Pdf::loadView('pdf.campaign-vouchers', compact('batch', 'vouchers'))
            ->setPaper('a4', 'portrait')->output();
        $path = 'voucher-pdfs/batch-'.$batch->id.'-'.now()->format('YmdHis').'.pdf';
        if ($batch->pdf_path) Storage::disk('local')->delete($batch->pdf_path);
        Storage::disk('local')->put($path, $content);
        $batch->update(['pdf_status' => 'ready', 'pdf_path' => $path, 'pdf_generated_at' => now()]);

        User::find($this->requesterId)?->notify(new ActivityNotification([
            'domain' => 'voucher', 'event' => 'pdf_ready', 'title' => 'Voucher PDF is ready',
            'message' => "The {$batch->title} print file is ready to download.",
            'severity' => 'success', 'subject' => 'voucher_batch:'.$batch->id,
            'url' => route('stamps.index', ['tab' => 'vouchers'], false),
        ]));
    }

    public function failed(Throwable $exception): void
    {
        VoucherBatch::whereKey($this->batchId)->update(['pdf_status' => 'failed']);
        User::find($this->requesterId)?->notify(new ActivityNotification([
            'domain' => 'voucher', 'event' => 'pdf_failed', 'title' => 'Voucher PDF generation failed',
            'message' => 'The voucher print file could not be generated. Please try again or contact support.',
            'severity' => 'warning', 'subject' => 'voucher_batch:'.$this->batchId,
            'url' => route('stamps.index', ['tab' => 'vouchers'], false),
        ]));
    }
}
