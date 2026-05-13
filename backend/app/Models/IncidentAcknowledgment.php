<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IncidentAcknowledgment — audit trail for parent acknowledgment of an incident.
 *
 * Each row captures:
 *  - WHO acknowledged (user_id)
 *  - WHAT name they typed as signature (signed_name)
 *  - FROM WHERE (ip_address)
 *  - WITH WHAT (user_agent)
 *  - WHEN (signed_at)
 *  - OPTIONAL FEEDBACK (comment)
 *
 * Designed to be admissible as basic evidence in licensing inspections.
 * NOT a full DocuSign-style cryptographic signature — v21+ may upgrade.
 */
class IncidentAcknowledgment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'signed_at' => 'datetime',
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
