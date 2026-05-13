<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EDocumentTemplate extends Model
{
    use SoftDeletes;
    protected $table = 'edocument_templates';
    protected $guarded = [];
    protected $casts = [
        'required' => 'boolean',
    ];
}
