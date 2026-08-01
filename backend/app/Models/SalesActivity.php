<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesActivity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'done'     => 'boolean',
        'done_at'  => 'datetime',
        'reminded' => 'boolean',
    ];

    public function lead()
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
