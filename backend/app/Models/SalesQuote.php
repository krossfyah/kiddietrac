<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuote extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    // Signature images and the private link never leave in a plain toArray()/JSON.
    protected $hidden = ['client_signature', 'countersignature', 'sign_token'];

    protected $casts = [
        'valid_until' => 'date',
        'sent_at'     => 'datetime',
        'subtotal'    => 'decimal:2',
        'discount'    => 'decimal:2',
        'total'       => 'decimal:2',
        'tax_total'   => 'decimal:2',
        'taxes'       => 'array',   // [{label, rate}] — 2026-10-16
        'prepared_for' => 'array',  // {company,name,title,address,email,phone}; NULL = from the lead
        'prepared_by'  => 'array',  // {name,title,email,phone}; NULL = rep name + sales contact
        // Online signing (2026-10-16)
        'sign_viewed_at'   => 'datetime',
        'client_signed_at' => 'datetime',
        'countersigned_at' => 'datetime',
        'declined_at'      => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SalesQuoteItem::class, 'quote_id')->orderBy('sort')->orderBy('id');
    }

    public function lead()
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }
}
