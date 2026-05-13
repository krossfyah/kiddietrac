<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'email', 'phone', 'password', 'first_name', 'last_name', 'preferred_name',
        'photo_url', 'locale', 'timezone', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ──────────────── Relationships ────────────────

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class)->where('active', true);
    }

    public function guardianRecords(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }

    public function families(): HasManyThrough
    {
        return $this->hasManyThrough(
            Family::class,
            Guardian::class,
            'user_id', 'id', 'id', 'family_id'
        );
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(StaffCertification::class)->where('active', true);
    }

    // ──────────────── Role Helpers ────────────────

    public function hasRole(string $role, ?int $centreId = null, ?int $agencyId = null): bool
    {
        return $this->roleAssignments()
            ->where('role', $role)
            ->when($centreId, fn($q) => $q->where('centre_id', $centreId))
            ->when($agencyId, fn($q) => $q->where('agency_id', $agencyId))
            ->exists();
    }

    public function isGuardian(): bool { return $this->hasRole('guardian'); }
    public function isEducator(): bool { return $this->hasRole('educator'); }
    public function isDirector(): bool { return $this->hasRole('centre_director'); }
    public function isAgencyAdmin(): bool { return $this->hasRole('agency_admin'); }

    public function accessibleCentres()
    {
        return Centre::query()
            ->whereIn('id', function ($q) {
                $q->select('centre_id')
                  ->from('role_assignments')
                  ->where('user_id', $this->id)
                  ->where('active', true)
                  ->whereNotNull('centre_id');
            })
            ->orWhereIn('agency_id', function ($q) {
                $q->select('agency_id')
                  ->from('role_assignments')
                  ->where('user_id', $this->id)
                  ->where('active', true)
                  ->where('role', 'agency_admin');
            });
    }

    // ──────────────── Computed ────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->preferred_name ?: $this->first_name;
    }
}
