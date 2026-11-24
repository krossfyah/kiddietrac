<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesLead extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'expected_close'   => 'date',
        'follow_up_date'   => 'date',
        'last_activity_at' => 'datetime',
        'value'            => 'decimal:2',
    ];

    /* Every lead with an email is on the subscriber list (2026-10-23): made or given an email
       here; leads written with DB::table are caught by kt:leads-subscribe. */
    protected static function booted(): void
    {
        static::saved(function (SalesLead $l) {
            if ($l->email && ($l->wasRecentlyCreated || $l->wasChanged('email'))) {
                \App\Support\LeadWelcome::subscribe((string) $l->email, (string) $l->name, 'lead', (string) $l->company);
            }
            // Won makes the customer (KT-CUS-0001); Approve links it to the agency it set up (2026-11-10, Sales -> Customers).
            if (($l->stage === 'won' && ($l->wasRecentlyCreated || $l->wasChanged('stage')))
                || ($l->converted_agency_id && $l->wasChanged('converted_agency_id'))) {
                \App\Support\SalesCustomers::ensure($l, auth()->id());
            }
        });
    }

    public function activities()
    {
        return $this->hasMany(SalesActivity::class, 'lead_id')->orderByDesc('id');
    }

    public function quotes()
    {
        return $this->hasMany(SalesQuote::class, 'lead_id')->orderByDesc('id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
