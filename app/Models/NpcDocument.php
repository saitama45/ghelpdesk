<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcDocument extends Model
{
    // Step 3 supporting document slots.
    public const TYPE_SECRETARY_CERTIFICATE = 'secretary_certificate';
    public const TYPE_OTHER_APPOINTMENT = 'other_appointment_document';
    public const TYPE_SEC_CERTIFICATE = 'sec_certificate';
    public const TYPE_GIS = 'gis';
    public const TYPE_BUSINESS_PERMIT = 'business_permit';
    // Step 4 payment proof.
    public const TYPE_PAYMENT_RECEIPT = 'payment_receipt';

    public const TYPES = [
        self::TYPE_SECRETARY_CERTIFICATE,
        self::TYPE_OTHER_APPOINTMENT,
        self::TYPE_SEC_CERTIFICATE,
        self::TYPE_GIS,
        self::TYPE_BUSINESS_PERMIT,
        self::TYPE_PAYMENT_RECEIPT,
    ];

    public const TYPE_LABELS = [
        self::TYPE_SECRETARY_CERTIFICATE => "Duly notarized Secretary's Certificate authorizing the appointment or designation of the DPO",
        self::TYPE_OTHER_APPOINTMENT => 'Other document demonstrating the validity of the appointment with accompanying authority to appoint',
        self::TYPE_SEC_CERTIFICATE => 'SEC Certificate of Registration',
        self::TYPE_GIS => 'Certified true copy of current General Information Sheet',
        self::TYPE_BUSINESS_PERMIT => 'Valid business permit',
        self::TYPE_PAYMENT_RECEIPT => 'Payment Receipt',
    ];

    protected $fillable = [
        'npc_status_id',
        'doc_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }
}
