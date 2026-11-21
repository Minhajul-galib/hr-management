<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'code', 'name', 'is_paid', 'entitlement_days', 'accrual',
        'carry_forward', 'carry_forward_limit_days', 'attachment_required', 'is_active',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'entitlement_days' => 'decimal:1',
        'carry_forward' => 'boolean',
        'carry_forward_limit_days' => 'decimal:1',
        'attachment_required' => 'boolean',
        'is_active' => 'boolean',
    ];
}
