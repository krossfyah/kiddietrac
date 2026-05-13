<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Agency extends Model {
    use SoftDeletes;
    protected $fillable = ['name','slug','logo_url','contact_email','contact_phone','timezone','locale','billing_status','trial_ends_at','settings'];
    protected $casts = ['settings' => 'array','trial_ends_at' => 'datetime'];
    public function centres(): HasMany { return $this->hasMany(Centre::class); }
}
