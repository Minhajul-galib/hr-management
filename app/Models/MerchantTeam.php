<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantTeam extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'ep_code', 'head_name', 'head_designation',
        'head_user_id', 'department_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
