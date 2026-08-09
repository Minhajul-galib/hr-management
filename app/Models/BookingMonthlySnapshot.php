<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingMonthlySnapshot extends Model
{
    protected $fillable = [
        'fiscal_year', 'month', 'supplier_id', 'unit_id',
        'yarn_count_id', 'fiber_type_id',
        'total_order_kg', 'total_received_kg',
        'pending_kg', 'balance_kg', 'captured_at',
    ];

    protected $casts = [
        'captured_at'       => 'datetime',
        'total_order_kg'    => 'decimal:2',
        'total_received_kg' => 'decimal:2',
        'pending_kg'        => 'decimal:2',
        'balance_kg'        => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(YarnSupplier::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
