<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'child_id', 'room_id', 'event_type', 'occurred_at', 'payload',
        'notes', 'photo_id', 'recorded_by_id', 'bulk_log_id', 'voice_logged', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'synced_at' => 'datetime',
            'payload' => 'array',
            'voice_logged' => 'boolean',
        ];
    }

    // ──────────────── Relationships ────────────────

    public function child(): BelongsTo { return $this->belongsTo(Child::class); }
    public function room(): BelongsTo  { return $this->belongsTo(Room::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_id'); }
    public function photo(): BelongsTo  { return $this->belongsTo(Media::class, 'photo_id'); }

    // ──────────────── Scopes ────────────────

    public function scopeToday($query) { return $query->whereDate('occurred_at', today()); }
    public function scopeOnDate($query, $date) { return $query->whereDate('occurred_at', $date); }
    public function scopeOfType($query, string|array $types) {
        return $query->whereIn('event_type', is_array($types) ? $types : [$types]);
    }

    // ──────────────── Display Helpers ────────────────

    /**
     * Returns a human-readable summary of this event for timelines.
     */
    public function getDisplayAttribute(): array
    {
        $p = $this->payload ?? [];
        return match($this->event_type) {
            'meal', 'snack' => [
                'title' => ucfirst($p['meal'] ?? $this->event_type),
                'detail' => $this->formatMealDetail($p),
                'icon' => 'coffee',
                'color' => '#E8A02E',
            ],
            'bottle' => [
                'title' => 'Bottle',
                'detail' => ($p['amount_oz'] ?? '') . ' oz · ' . ($p['contents'] ?? 'formula'),
                'icon' => 'baby-bottle',
                'color' => '#3DB6A0',
            ],
            'nap_start' => [
                'title' => 'Started napping',
                'detail' => 'Down at ' . $this->occurred_at->format('g:i A'),
                'icon' => 'moon', 'color' => '#A974C9',
            ],
            'nap_end' => [
                'title' => 'Woke from nap',
                'detail' => $this->formatNapDuration(),
                'icon' => 'sun', 'color' => '#E8A02E',
            ],
            'diaper' => [
                'title' => 'Diaper change',
                'detail' => ucfirst($p['type'] ?? 'changed'),
                'icon' => 'droplet', 'color' => '#3DB6A0',
            ],
            'bathroom' => [
                'title' => 'Bathroom',
                'detail' => ($p['self_initiated'] ?? false) ? 'Self-initiated' : 'Assisted',
                'icon' => 'droplet', 'color' => '#3DB6A0',
            ],
            'activity' => [
                'title' => $p['name'] ?? 'Activity',
                'detail' => ($p['domain'] ?? '') . ($p['duration_min'] ?? false ? " · {$p['duration_min']} min" : ''),
                'icon' => 'sparkles', 'color' => '#1F6080',
            ],
            'mood' => [
                'title' => 'Mood check',
                'detail' => ucfirst($p['score'] ?? 'recorded'),
                'icon' => 'smile', 'color' => '#8EC73C',
            ],
            'incident' => [
                'title' => 'Incident logged',
                'detail' => 'See incident report',
                'icon' => 'alert-triangle', 'color' => '#D85A6C',
            ],
            'medication' => [
                'title' => 'Medication administered',
                'detail' => $p['medication_name'] ?? '',
                'icon' => 'pill', 'color' => '#B57F1A',
            ],
            default => [
                'title' => str_replace('_', ' ', ucfirst($this->event_type)),
                'detail' => $this->notes ?? '',
                'icon' => 'circle', 'color' => '#5B6B78',
            ],
        };
    }

    private function formatMealDetail(array $p): string
    {
        $parts = [];
        if (!empty($p['items'])) $parts[] = implode(', ', $p['items']);
        if (!empty($p['amount'])) {
            $parts[] = match($p['amount']) {
                'all' => 'ate all',
                'most' => 'ate most',
                'half' => 'ate half',
                'little' => 'ate a little',
                'none' => 'did not eat',
                default => $p['amount'],
            };
        }
        return implode(' · ', $parts);
    }

    private function formatNapDuration(): string
    {
        if ($this->event_type !== 'nap_end') return '';
        $start = DailyEvent::where('child_id', $this->child_id)
            ->where('event_type', 'nap_start')
            ->where('occurred_at', '<', $this->occurred_at)
            ->orderByDesc('occurred_at')
            ->first();
        if (!$start) return '';
        $mins = $start->occurred_at->diffInMinutes($this->occurred_at);
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return $h > 0 ? "Slept {$h}h {$m}m" : "Slept {$m}m";
    }
}
