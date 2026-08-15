<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A screenshot or file backing a verdict or a finding. The source workbook kept
 * these on a separate sheet as SS1..SSn and referenced them by name from the
 * remarks text; here the file hangs off the record it evidences.
 */
class UatEvidence extends Model
{
    use HasFactory;

    protected $table = 'uat_evidence';

    protected $fillable = [
        'uat_cycle_id',
        'uat_case_result_id',
        'uat_finding_id',
        'label',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by_user_id',
        'uploaded_by_name',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uat_cycle_id' => 'integer',
        'uat_case_result_id' => 'integer',
        'uat_finding_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
    ];

    protected $appends = ['url', 'is_image'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(UatCaseResult::class, 'uat_case_result_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(UatFinding::class, 'uat_finding_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Root-relative URL, deliberately not the absolute one.
     *
     * Storage::url() builds its result from APP_URL, which is routinely wrong:
     * in dev it carries whatever port was last configured (a cycle's evidence
     * pointed at :8001 while the app was served on :8000, so every thumbnail
     * broke), and behind a proxy it can disagree with the host that actually
     * served the page. A path always resolves against the current origin.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        $url = Storage::disk('public')->url($this->file_path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /** Screenshots render inline; anything else falls back to a download link. */
    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
