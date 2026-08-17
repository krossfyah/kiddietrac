<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Centre extends Model {
    use SoftDeletes;
    protected $fillable = ['agency_id','name','slug','license_number','license_capacity','address_line1','address_line2','city','province','postal_code','country','phone','email','open_time','close_time','cwelcc_enrolled','status','settings','external_id','external_source','supervisor_first_name','supervisor_last_name','date_of_birth'];
    protected $casts = ['cwelcc_enrolled' => 'boolean','settings' => 'array'];
    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
    public function rooms(): HasMany { return $this->hasMany(Room::class); }
    public function families(): HasMany { return $this->hasMany(Family::class); }
}
