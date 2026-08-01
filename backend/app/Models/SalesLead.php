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
