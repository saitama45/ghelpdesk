<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A message a member sent from the mobile app's Suggestion Box.
 *
 * Deliberately NOT in CompanyContext::SCOPED_MODELS: it is written by a
 * roleless mobile login, whose resolved "active entity" is not necessarily
 * CBTL, so Api\SuggestionController stamps `company_id` explicitly instead.
 */
class CustomerSuggestion extends Model
{
    protected $fillable = [
        'customer_id',
        'user_id',
        'company_id',
        'subject',
        'message',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'user_id' => 'integer',
        'company_id' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
