<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StaffCertification extends Model {
    protected $fillable = ['user_id','cert_type','certifier','issued_at','expires_at','document_url','active'];
    protected $casts = ['issued_at'=>'date','expires_at'=>'date','active'=>'boolean'];
    public $timestamps = ['created_at'];
    const UPDATED_AT = null;
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
