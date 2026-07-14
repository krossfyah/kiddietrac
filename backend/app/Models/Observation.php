<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `observations` table has created_at but NO updated_at.
 *
 * Eloquent maintains both timestamps by default, so every Observation::create()
 * tried to write an updated_at column that does not exist and the insert died
 * with a 500 — saving an observation has never worked through this model. Tell
 * Eloquent the truth about the table rather than adding a column the app has no
 * use for: an observation is a record of a moment, and it isn't edited in place.
 */
class Observation extends Model
{
    protected $guarded = [];

    /** created_at only. */
    public const UPDATED_AT = null;

    protected $casts = [
        'hdlh_milestones' => 'array',
        'media_ids' => 'array',
        'shared_with_family' => 'boolean',
        'ai_generated' => 'boolean',
        'observed_at' => 'datetime',
        'ai_processed_at' => 'datetime',
        'educator_reviewed_at' => 'datetime',
    ];
}
