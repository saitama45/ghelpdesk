<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Models\VoucherSaleClaim;
use App\Models\VoucherVerificationAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generateCodes(VoucherBatch $batch): void
    {
        $rows = [];
        do {
            while (count($rows) < $batch->quantity) {
                $raw = '';
                for ($i = 0; $i < 16; $i++) {
                    $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
                }
                $code = 'VCH-'.implode('-', str_split($raw, 4));
                $rows[$code] = [
                    'voucher_batch_id' => $batch->id,
                    'code' => $code,
                    'status' => 'issued',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk(array_keys($rows), 1000) as $candidateChunk) {
                foreach (Voucher::whereIn('code', $candidateChunk)->pluck('code') as $existingCode) {
                    unset($rows[$existingCode]);
                }
            }
        } while (count($rows) < $batch->quantity);

        foreach (array_chunk(array_values($rows), 250) as $chunk) {
            Voucher::insert($chunk);
        }
    }

    public function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    public function verify(string $rawCode, int $userId, ?int $storeId, ?int $companyId): array
    {
        $code = $this->normalizeCode($rawCode);
        $voucher = Voucher::with([
            'batch.company:id,name,code,logo',
            'activeRedemption.customer:id,name,phone,email',
            'activeRedemption.store:id,code,name',
            'activeRedemption.cashier:id,name',
            'activeRedemption.cashierVendor:id,name',
        ])->where('code', $code)->first();

        $result = 'invalid';
        if ($voucher && (int) $voucher->batch->company_id === $companyId) {
            $result = match (true) {
                $voucher->status === 'void' => 'void',
                $voucher->status === 'used' => 'already_used',
                default => $voucher->batch->effectiveStatus(),
            };
        } else {
            $voucher = null;
        }

        VoucherVerificationAttempt::create([
            'voucher_id' => $voucher?->id,
            'scanned_code' => mb_substr($code, 0, 255),
            'result' => $result,
            'store_id' => $storeId,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);

        return [
            'result' => $result,
            'message' => $this->resultMessage($result),
            'voucher' => $voucher ? [
                'id' => $voucher->id,
                'code' => $voucher->code,
                'status' => $voucher->status,
                'value' => $voucher->batch->face_value,
                'batch' => [
                    'id' => $voucher->batch->id,
                    'title' => $voucher->batch->title,
                    'partner_name' => $voucher->batch->partner_name,
                    'claim_starts_on' => $voucher->batch->claim_starts_on?->format('Y-m-d'),
                    'claim_ends_on' => $voucher->batch->claim_ends_on?->format('Y-m-d'),
                ],
                'redemption' => $voucher->activeRedemption ? [
                    'id' => $voucher->activeRedemption->id,
                    'customer' => $voucher->activeRedemption->customer,
                    'store' => $voucher->activeRedemption->store,
                    'cashier' => $voucher->activeRedemption->cashier,
                    'cashier_vendor' => $voucher->activeRedemption->cashierVendor,
                    'receipt_number' => $voucher->activeRedemption->receipt_number,
                    'sale_date' => $voucher->activeRedemption->sale_date?->format('Y-m-d'),
                    'gross_sale_total' => $voucher->activeRedemption->gross_sale_total,
                    'applied_amount' => $voucher->activeRedemption->applied_amount,
                    'redeemed_at' => $voucher->activeRedemption->redeemed_at?->toIso8601String(),
                ] : null,
            ] : null,
        ];
    }

    public function redeem(array $data, int $userId, int $companyId): VoucherRedemption
    {
        return DB::transaction(function () use ($data, $userId, $companyId) {
            $voucher = Voucher::with('batch')->where('code', $this->normalizeCode($data['code']))
                ->lockForUpdate()->first();

            if (! $voucher || (int) $voucher->batch->company_id !== $companyId) {
                throw ValidationException::withMessages(['code' => 'That voucher code is invalid.']);
            }
            $voucher->setRelation('batch', VoucherBatch::query()->lockForUpdate()->findOrFail($voucher->voucher_batch_id));
            if ($voucher->status !== 'issued' || $voucher->batch->effectiveStatus() !== 'active') {
                throw ValidationException::withMessages(['code' => $this->resultMessage(
                    $voucher->status === 'used' ? 'already_used' : ($voucher->status === 'void' ? 'void' : $voucher->batch->effectiveStatus())
                )]);
            }

            $customer = $this->resolveCustomer($data, $userId);
            $gross = round((float) $data['gross_sale_total'], 2);
            $face = round((float) $voucher->batch->face_value, 2);
            $applied = min($gross, $face);
            $saleKey = self::saleKey((int) $data['store_id'], $data['sale_date'], $data['receipt_number']);

            try {
                $redemption = VoucherRedemption::create([
                    'voucher_id' => $voucher->id,
                    'customer_id' => $customer->id,
                    'store_id' => $data['store_id'],
                    'receipt_number' => trim($data['receipt_number']),
                    'sale_date' => $data['sale_date'],
                    'gross_sale_total' => $gross,
                    'applied_amount' => $applied,
                    'forfeited_amount' => max(0, $face - $applied),
                    'redeemed_at' => now(),
                    'redeemed_by' => $userId,
                ]);
                VoucherSaleClaim::create(['sale_key' => $saleKey, 'voucher_redemption_id' => $redemption->id]);
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'receipt_number' => 'A voucher has already been applied to this store, sale date, and receipt.',
                ]);
            }

            $voucher->update(['status' => 'used']);
            return $redemption->load(['voucher.batch', 'customer', 'store', 'cashier', 'cashierVendor']);
        });
    }

    public function voidRedemption(VoucherRedemption $redemption, string $reason, int $userId, int $companyId): void
    {
        DB::transaction(function () use ($redemption, $reason, $userId, $companyId) {
            $locked = VoucherRedemption::with('voucher.batch')->lockForUpdate()->findOrFail($redemption->id);
            if ((int) $locked->voucher->batch->company_id !== $companyId) abort(404);
            if ($locked->voided_at) {
                throw ValidationException::withMessages(['reason' => 'This redemption has already been voided.']);
            }
            $locked->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
            VoucherSaleClaim::where('voucher_redemption_id', $locked->id)->delete();
            $locked->voucher->update(['status' => 'issued']);
        });
    }

    public function voidVoucher(Voucher $voucher, string $reason, int $userId, int $companyId): void
    {
        DB::transaction(function () use ($voucher, $reason, $userId, $companyId) {
            $locked = Voucher::with('batch')->lockForUpdate()->findOrFail($voucher->id);
            if ((int) $locked->batch->company_id !== $companyId) abort(404);
            if ($locked->status !== 'issued') {
                throw ValidationException::withMessages(['reason' => 'Only an unused voucher can be voided.']);
            }
            $locked->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
        });
    }

    public static function saleKey(int $storeId, string $saleDate, string $receipt): string
    {
        return hash('sha256', $storeId.'|'.$saleDate.'|'.mb_strtoupper(trim($receipt)));
    }

    private function resolveCustomer(array $data, int $userId): Customer
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::where('is_active', true)->find($data['customer_id']);
            if (! $customer) throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
            return $customer;
        }

        $phone = trim($data['new_customer_phone'] ?? '');
        $existing = Customer::where('phone', $phone)->where('is_active', true)->first();
        if ($existing) return $existing;

        return Customer::create([
            'name' => trim($data['new_customer_name']),
            'phone' => $phone,
            'email' => $data['new_customer_email'] ?? null,
            'is_active' => true,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    private function resultMessage(string $result): string
    {
        return match ($result) {
            'active' => 'Verified — this voucher is valid and ready to apply as payment.',
            'already_used' => 'This voucher has already been used.',
            'void' => 'This voucher was voided and cannot be used.',
            'draft' => 'This voucher batch has not been activated.',
            'not_yet_valid' => 'This voucher is not yet within its claim period.',
            'expired' => 'This voucher has expired.',
            'suspended' => 'This voucher batch is temporarily suspended.',
            'cancelled' => 'This voucher batch was cancelled.',
            default => 'That voucher code is invalid.',
        };
    }
}
