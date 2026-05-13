<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Room extends Model {
    protected $fillable = ['centre_id','name','age_group','age_min_months','age_max_months','capacity','ratio_educators','ratio_children','color_hex','active'];
    protected $casts = ['active' => 'boolean'];
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
}
