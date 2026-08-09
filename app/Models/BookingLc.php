<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingLc extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_id', 'lc_number', 'lc_issue_date',
        'lc_factory', 'commercial_invoice',
    ];

    protected $casts = [
        'lc_issue_date' => 'date',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
