<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A screenshot or file backing a QAT verdict or finding. Sibling of
 * {@see UatEvidence}.
 */
class QatEvidence extends Model
{
    use HasFactory;

    protected $table = 'qat_evidence';

    protected $fillable = [
        'qat_cycle_id',
        'qat_case_result_id',
        'qat_finding_id',
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
        'qat_cycle_id' => 'integer',
        'qat_case_result_id' => 'integer',
        'qat_finding_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
    ];

    protected $appends = ['url', 'is_image'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(QatCaseResult::class, 'qat_case_result_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(QatFinding::class, 'qat_finding_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Root-relative URL, deliberately not the absolute one.
     *
     * Storage::url() builds its result from APP_URL, which is routinely wrong: in
     * dev it carries whatever port was last configured, and behind a proxy it can
     * disagree with the host that actually served the page. A path always resolves
     * against the current origin.
     */
    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path) {
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
