<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person at the vendor, maintained by the vendor in the portal
 * (/vendor/profile). Read-only here: contacts carry no approval — they are
 * directory information, not a control.
 */
class VendorContact extends Model
{
    protected $table = 'portal_vendor_contacts';

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
