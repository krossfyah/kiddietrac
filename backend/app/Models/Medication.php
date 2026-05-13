<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medication extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = [
        'starts_on' => 'date',
        'expires_on' => 'date',
        'authorized_at' => 'datetime',
        'requires_refrigeration' => 'boolean',
        'is_prescription' => 'boolean',
    ];
}
