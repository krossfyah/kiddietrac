<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationLog extends Model
{
    protected $guarded = [];
    protected $casts = [
        'administered_at' => 'datetime',
        'parent_notified_at' => 'datetime',
    ];
}
