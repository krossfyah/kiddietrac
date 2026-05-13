<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EDocumentSignature extends Model
{
    protected $table = 'edocument_signatures';
    protected $guarded = [];
    protected $casts = [
        'signed_at' => 'datetime',
    ];
}
