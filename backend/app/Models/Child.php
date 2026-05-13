<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Child extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'family_id', 'first_name', 'last_name', 'preferred_name', 'pronouns',
        'date_of_birth', 'gender', 'photo_url', 'health_card_last4',
        'doctor_name', 'doctor_phone', 'medical_notes', 'dietary_notes',
        'cultural_notes', 'preferred_lang', 'enrollment_status',
        'enrolled_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrolled_at' => 'date',
            'withdrawn_at' => 'date',
        ];
    }

    // ──────────────── Relationships ────────────────

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)
            ->whereNull('end_date')
            ->orderByDesc('start_date');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function healthFlags(): HasMany
    {
        return $this->hasMany(ChildHealthFlag::class)->where('active', true);
    }

    public function allergies(): HasMany
    {
        return $this->healthFlags()->where('flag_type', 'allergy');
    }

    public function activeMedications(): HasMany
    {
        return $this->hasMany(Medication::class)
            ->where('active', true)
            ->where('expires_at', '>', now());
    }

    public function dailyEvents(): HasMany
    {
        return $this->hasMany(DailyEvent::class)->orderByDesc('occurred_at');
    }

    public function checkEvents(): HasMany
    {
        return $this->hasMany(CheckEvent::class)->orderByDesc('occurred_at');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class)->orderByDesc('observed_at');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->orderByDesc('occurred_at');
    }

    public function immunizations(): HasMany
    {
        return $this->hasMany(Immunization::class);
    }

    public function activeSubsidies(): HasMany
    {
        return $this->hasMany(Subsidy::class)
            ->where('active', true)
            ->where('valid_from', '<=', today())
            ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', today()));
    }

    public function authorizedPickups(): HasMany
    {
        return $this->hasMany(AuthorizedPickup::class);
    }

    public function digests(): HasMany
    {
        return $this->hasMany(AiDailyDigest::class);
    }

    // ──────────────── Scopes ────────────────

    public function scopeEnrolled($query)
    {
        return $query->where('enrollment_status', 'enrolled');
    }

    public function scopeInRoom($query, int $roomId)
    {
        return $query->whereHas('currentEnrollment', fn($q) => $q->where('room_id', $roomId));
    }

    // ──────────────── Computed Attributes ────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->preferred_name ?: $this->first_name;
    }

    public function getAgeAttribute(): array
    {
        $now = Carbon::now();
        $dob = $this->date_of_birth;
        $years = $dob->diffInYears($now);
        $months = $dob->copy()->addYears($years)->diffInMonths($now);
        return [
            'years' => (int) $years,
            'months' => (int) $months,
            'total_months' => (int) $dob->diffInMonths($now),
            'human' => $years > 0 ? "{$years}y {$months}m" : "{$months} months",
        ];
    }

    public function getIsAtCentreAttribute(): bool
    {
        $latest = $this->checkEvents()->first();
        return $latest && $latest->event_type === 'check_in';
    }

    // ──────────────── Business Logic ────────────────

    /**
     * Get monthly fee after subsidy applied.
     */
    public function calculateMonthlyFee(): array
    {
        $enrollment = $this->currentEnrollment;
        if (!$enrollment) return ['gross' => 0, 'subsidy' => 0, 'net' => 0];

        $gross = $enrollment->monthly_fee;
        $subsidy = $this->activeSubsidies->sum('monthly_amount');

        return [
            'gross' => $gross,
            'subsidy' => $subsidy,
            'net' => max(0, $gross - $subsidy),
        ];
    }

    /**
     * Returns urgent health flags requiring banner display.
     */
    public function urgentHealthFlags(): array
    {
        return $this->healthFlags()
            ->whereIn('severity', ['severe', 'life_threatening'])
            ->get()
            ->map(fn($f) => [
                'type' => $f->flag_type,
                'category' => $f->category,
                'severity' => $f->severity,
                'action_plan' => $f->action_plan,
            ])
            ->toArray();
    }
}
