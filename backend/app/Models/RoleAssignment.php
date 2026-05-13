<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class RoleAssignment extends Model {
    protected $fillable = ['user_id','role','agency_id','centre_id','active'];
    protected $casts = ['active' => 'boolean'];
    public $timestamps = false;
    protected $attributes = ['active' => true];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
}
