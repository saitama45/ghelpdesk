<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of the vendor portal's own reference lists (linkportal writes
 * this table; the back office only ever resolves labels from it).
 *
 * `document_type` is the list behind the portal's "Document Type" picker on
 * /vendor/documents — the label shown beside every uploaded accreditation file.
 */
class PortalReferenceOption extends Model
{
    protected $table = 'portal_reference_options';

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
