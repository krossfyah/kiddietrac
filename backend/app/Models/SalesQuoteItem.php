<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuoteItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty'        => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];
}
