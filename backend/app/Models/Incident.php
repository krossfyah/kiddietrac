<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Incident — v20 full incident model.
 *
 * Replaces the v4 stub. Adds casts, scopes, and relationships needed by the
 * IncidentController, parent UI, and director review queue.
 */
class Incident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at'           => 'datetime',
        'submitted_at'          => 'datetime',
        'reviewed_at'           => 'datetime',
        'parent_notified_at'    => 'datetime',
        'acknowledged_at'       => 'datetime',
        'closed_at'             => 'datetime',
        'is_serious_occurrence' => 'boolean',
        'witnesses'             => 'array',
        'body_parts_affected'   => 'array',
    ];

    /* ===== Relationships ===== */

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(IncidentAcknowledgment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IncidentAttachment::class);
    }

    /* ===== Scopes ===== */

    public function scopeOpen($q)
    {
        return $q->whereNotIn('status', ['closed', 'acknowledged']);
    }

    public function scopeAwaitingDirector($q)
    {
        return $q->where('status', 'submitted');
    }

    public function scopeAwaitingParent($q)
    {
        return $q->where('status', 'parent_notified');
    }

    public function scopeForCentre($q, int $centreId)
    {
        return $q->where('centre_id', $centreId);
    }

    public function scopeSeriousOccurrence($q)
    {
        return $q->where('is_serious_occurrence', true);
    }

    /* ===== Helpers ===== */

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['closed', 'acknowledged'], true);
    }

    public function canBeAcknowledged(): bool
    {
        return $this->status === 'parent_notified';
    }
}
