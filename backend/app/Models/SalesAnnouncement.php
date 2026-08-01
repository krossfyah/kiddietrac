<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesAnnouncement extends Model
{
    protected $guarded = [];

    protected $casts = ['pinned' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
