<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesProduct extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price'  => 'decimal:2',
        'active' => 'boolean',
    ];
}
