<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AiDailyDigest — cached AI-generated end-of-day summary for one child for one day.
 *
 * Written by App\Services\AiDigestService::generate().
 * Read by App\Http\Controllers\Api\DailyEventController::digest().
 *
 * One row per (child_id, digest_date) — unique constraint enforced in migration.
 */
class AiDailyDigest extends Model
{
    // Single timestamp 'created_at' is managed by DB DEFAULT (useCurrent),
    // and we set 'generated_at' explicitly. No 'updated_at' column.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'digest_date' => 'date',
        'generated_at' => 'datetime',
        'source_event_ids' => 'array',
        'tokens_used' => 'integer',
    ];

    /**
     * Relationship: parent Child model.
     */
    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
