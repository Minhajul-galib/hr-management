<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingShipment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_id', 'bl_qty_ctn', 'org_document_date',
        'ex_factory_date', 'etd', 'eta',
        'received_qty', 'received_date', 'remark',
    ];

    protected $casts = [
        'org_document_date' => 'date',
        'ex_factory_date'   => 'date',
        'etd'               => 'date',
        'eta'               => 'date',
        'received_date'     => 'date',
        'received_qty'      => 'decimal:2',
        'bl_qty_ctn'        => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
