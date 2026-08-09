<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'fiscal_year', 'supplier_id', 'merchant_team_id',
        'yarn_count_id', 'fiber_type_id', 'booking_type_id',
        'unit_id', 'order_qty_kg', 'pi_number',
        'pi_received_date', 'status', 'created_by',
    ];

    protected $casts = [
        'pi_received_date' => 'date',
        'order_qty_kg'     => 'decimal:2',
        'fiscal_year'      => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(YarnSupplier::class);
    }

    public function merchantTeam()
    {
        return $this->belongsTo(MerchantTeam::class);
    }

    public function yarnCount()
    {
        return $this->belongsTo(YarnCount::class);
    }

    public function fiberType()
    {
        return $this->belongsTo(FiberType::class);
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function lc()
    {
        return $this->hasOne(BookingLc::class);
    }

    public function shipment()
    {
        return $this->hasOne(BookingShipment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getBalanceQtyAttribute()
    {
        $received = $this->shipment?->received_qty ?? 0;
        return $this->order_qty_kg - $received;
    }
}
