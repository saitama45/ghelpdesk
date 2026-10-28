<?php

use App\Models\StockReceiving;
use App\Models\StockTransfer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * StockTransferController::post had no status guard, so a transfer posted twice
     * (TRF-20260820-KMPN1Z: five pieces, CFE I-WAREHOUSE -> CBTL TOL, posted 8s apart)
     * deducted its origin twice and opened a second receiving for the same pieces, and
     * both receivings were received. The origin read one short per piece (SKU100075
     * showed 0 at CFE I-WAREHOUSE right after a +1 stock-in) and the destination one over.
     *
     * One post = one 'Transfer Out' + one receiving. For every transfer row with more than
     * one live 'Transfer Out', this keeps the first Transfer Out and the receiving that
     * shows where the piece went, and voids the other posts. Nothing is deleted:
     *  - their ledger rows go to quantity 0 and stay in the trail, like the quantity-0
     *    'Receiving Unserved' rows;
     *  - a voided receiving still For Receiving is closed as Declined so it can never be
     *    received. A Received one keeps its received_quantity: at 0,
     *    StockReceiving::neverArrivedTransferIds would read "never arrived" and take the
     *    real piece off the destination shelf.
     *
     * Kept receiving: the first one Received with a quantity (the piece arrived); else,
     * when all are in the same state, the first one. Mixed un-arrived outcomes (one
     * returned to source, one still in transit) cannot be settled from data, so they are
     * logged and left for a person.
     *
     * Idempotent: only non-zero Transfer Out rows count as live, so a rerun finds nothing.
     */
    public function up(): void
    {
        $transferIds = DB::table('inventory_transactions')
            ->where('reference_type', StockTransfer::class)
            ->where('transaction_type', 'Transfer Out')
            ->where('quantity', '!=', 0)
            ->groupBy('reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('reference_id');

        foreach ($transferIds as $transferId) {
            DB::transaction(fn () => $this->voidDuplicatePosts((int) $transferId));
        }
    }

    public function down(): void
    {
        // Forward-only: restoring the voided quantities would put the double deduction back.
    }

    private function voidDuplicatePosts(int $transferId): void
    {
        $transfer = DB::table('stock_transfers')->where('id', $transferId)->first();
        $outs = DB::table('inventory_transactions')
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transferId)
            ->where('transaction_type', 'Transfer Out')
            ->where('quantity', '!=', 0)
            ->orderBy('id')
            ->get(['id', 'quantity']);

        // Every post writes exactly -quantity; any other shape is not a plain double post.
        if (! $transfer || $outs->contains(fn ($out) => (int) $out->quantity !== -(int) $transfer->quantity)) {
            Log::warning('Duplicate transfer post left for manual review: unexpected Transfer Out shape.', [
                'stock_transfer_id' => $transferId,
            ]);

            return;
        }

        $receivings = DB::table('stock_receivings')
            ->where('stock_transfer_id', $transferId)
            ->orderBy('id')
            ->get(['id', 'receiving_no', 'status', 'received_quantity', 'received_by', 'received_at', 'remarks']);

        $keep = $receivings->first(fn ($r) => $r->status === 'Received' && (int) $r->received_quantity > 0)
            ?? ($receivings->pluck('status')->unique()->count() === 1 ? $receivings->first() : null);

        if ($receivings->isNotEmpty() && ! $keep) {
            Log::warning('Duplicate transfer post left for manual review: its receivings disagree.', [
                'stock_transfer_id' => $transferId,
                'transfer_no' => $transfer->transfer_no,
                'receivings' => $receivings->map(fn ($r) => "{$r->id}:{$r->status}")->all(),
            ]);

            return;
        }

        $now = now();
        $voidedOuts = $outs->slice(1)->pluck('id')->all();
        DB::table('inventory_transactions')->whereIn('id', $voidedOuts)->update(['quantity' => 0, 'updated_at' => $now]);

        $voidedReceivings = [];
        foreach ($receivings as $receiving) {
            if ($receiving->id === $keep->id) {
                continue;
            }

            DB::table('inventory_transactions')
                ->where('reference_type', StockReceiving::class)
                ->where('reference_id', $receiving->id)
                ->where('quantity', '!=', 0)
                ->update(['quantity' => 0, 'updated_at' => $now]);

            $note = "Voided: duplicate of {$keep->receiving_no} (transfer {$transfer->transfer_no} was posted twice); not counted in stock on hand.";
            $changes = ['remarks' => trim($note.' '.$receiving->remarks), 'updated_at' => $now];
            if ($receiving->status === 'For Receiving') {
                $changes += ['status' => 'Declined', 'received_quantity' => 0];
            }
            DB::table('stock_receivings')->where('id', $receiving->id)->update($changes);
            $voidedReceivings[] = $receiving->id;
        }

        // The transfer follows the kept receiving: a voided one may have been the last to act on it.
        $status = $keep ? match ($keep->status) {
            'Received' => 'Received',
            'Declined' => 'Declined',
            default => 'Posted',
        } : $transfer->status;
        if ($status !== $transfer->status) {
            DB::table('stock_transfers')->where('id', $transferId)->update([
                'status' => $status,
                'received_by' => $status === 'Received' ? $keep->received_by : null,
                'received_at' => $status === 'Received' ? $keep->received_at : null,
                'updated_at' => $now,
            ]);
        }

        Log::info('Voided duplicate stock transfer post.', [
            'stock_transfer_id' => $transferId,
            'transfer_no' => $transfer->transfer_no,
            'kept_receiving_id' => $keep?->id,
            'voided_transfer_out_ids' => $voidedOuts,
            'voided_receiving_ids' => $voidedReceivings,
        ]);
    }
};
