<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcurementFollowup extends Model
{
    use SoftDeletes;

    protected $table = 'procurement_followups';

    protected $fillable = [
        'supplier',
        'merchandiser',
        'merchant_team',
        'yarn_count',
        'booking_type',
        'fiber',
        'fiber_description',
        'order_qty_kg',
        'pi_number',
        'pi_received_date',
        'lc_number',
        'lc_issue_date',
        'lc_factory',
        'commercial_invoice',
        'bl_qty_ctn',
        'org_document_date',
        'ex_factory_date',
        'etd',
        'eta',
        'received_qty',
        'received_date',
        'balance_qty',
        'remark',
        'fiscal_year',
        'import_batch',
    ];

    protected $casts = [
        'order_qty_kg' => 'decimal:3',
        'bl_qty_ctn' => 'decimal:3',
        'received_qty' => 'decimal:3',
        'balance_qty' => 'decimal:3',
        'pi_received_date' => 'date',
        'lc_issue_date' => 'date',
        'org_document_date' => 'date',
        'ex_factory_date' => 'date',
        'etd' => 'date',
        'eta' => 'date',
        'received_date' => 'date',
        'fiscal_year' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            // fiscal_year derived from pi_received_date (calendar year).
            if (!empty($m->pi_received_date)) {
                try {
                    $m->fiscal_year = (int) $m->pi_received_date->format('Y');
                } catch (\Throwable) {
                    // leave as-is
                }
            }

            $ordered  = $m->order_qty_kg  !== null ? (float) $m->order_qty_kg  : null;
            $received = $m->received_qty !== null ? (float) $m->received_qty : null;
            $m->balance_qty = ($ordered !== null || $received !== null)
                ? ($ordered ?? 0) - ($received ?? 0)
                : null;
        });
    }
}

