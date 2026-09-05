<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An accreditation/compliance file a vendor uploaded through the portal's
 * /vendor/documents screen. linkportal owns the writes; the back office reads
 * them on /vendors so an approver can see what they are accrediting.
 *
 * The row lives in the shared database, but the FILE lives on the portal's
 * disk — `App\Services\PortalDocumentStorage` is the only thing that fetches it.
 */
class VendorDocument extends Model
{
    use SoftDeletes;

    protected $table = 'portal_vendor_documents';

    /** Statuses the portal's reviewer sets. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** Expiry inside this many days is worth flagging to an approver. */
    public const EXPIRY_WARNING_DAYS = 60;

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'reviewed_at' => 'datetime',
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function documentType()
    {
        return $this->belongsTo(PortalReferenceOption::class, 'document_type_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The version this one replaced, when the vendor re-uploaded a document. */
    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * How the panel should render the file: an image gets a thumbnail and the
     * zoom viewer, a PDF opens in a tab, everything else can only be downloaded.
     */
    public function fileKind(): string
    {
        $mime = strtolower((string) $this->mime_type);
        $extension = strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return 'image';
        }

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }

        return $extension ?: 'file';
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->endOfDay()->isPast();
    }

    public function isExpiringSoon(): bool
    {
        return $this->expiry_date !== null
            && ! $this->isExpired()
            && $this->expiry_date->startOfDay()->lte(now()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    /** "2.8 MB" — the portal stores raw bytes. */
    public function humanFileSize(): ?string
    {
        $bytes = (int) $this->file_size;

        if ($bytes <= 0) {
            return null;
        }

        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $step]) {
            if ($bytes >= $step) {
                return round($bytes / $step, $bytes / $step >= 10 ? 0 : 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
