<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Family extends Model {
    use SoftDeletes;
    protected $fillable = ['centre_id','family_name','primary_phone','primary_email','address_line1','address_line2','city','province','postal_code','preferred_lang','billing_split','notes'];
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function guardians(): HasMany { return $this->hasMany(Guardian::class); }
    public function children(): HasMany { return $this->hasMany(Child::class); }
}
