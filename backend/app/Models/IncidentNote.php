<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IncidentNote — staff-internal note / detail added to an incident after the
 * fact, forming an append-only audit trail (who wrote it, when). Never shown to
 * guardians. Mirrors the IncidentAcknowledgment persistence pattern.
 */
class IncidentNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
