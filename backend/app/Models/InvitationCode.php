<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitationCode extends Model
{
    protected $guarded = [];
    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->used_count >= $this->max_uses) return false;
        return true;
    }
}
