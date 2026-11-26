<?php

namespace App\Models;

use App\Support\Payroll\Formula;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a company's salary (Basic, Gross, House Rent, ...): a title the user picks and
 * a formula that works it out, e.g. "[Grade] * 25 + 950", "[Gross] * 60%" or "2000" (see
 * Formula). A formula may use [Grade] and any other component of the same pay scale by its title.
 */
class PayScaleLine extends Model
{
    protected $fillable = ['pay_scale_id', 'position', 'title', 'formula'];

    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /** The title as formulas refer to it (see Formula::key). */
    public function key(): string
    {
        return Formula::key($this->title);
    }
}
