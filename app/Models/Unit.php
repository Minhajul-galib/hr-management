<?php

namespace App\Models;

use App\Support\MasterData\Code;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'unit_code',
        'name',
        'short_form',
        'location',
        'contact_person',
        'phone_number',
        'email',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Units added by an Excel import are credited to whoever imported them, as those added on the form are.
        static::creating(fn (self $unit) => $unit->created_by ??= auth()->id());
    }

    /**
     * The rules a unit must pass, shared by the form and the Excel import.
     *
     * @return array<string, list<mixed>|string>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'unit_code' => self::codeRules($ignoreId),
            'short_form' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Rules for a unit code. Capitals and small letters count as the same code, so
     * F02-PDD and f02-pdd cannot both exist; a unit in the Trash still holds its code.
     *
     * @return list<mixed>
     */
    public static function codeRules(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'max:'.Code::MAX_LENGTH, 'regex:'.Code::UNIT_PATTERN,
            function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
                $holder = is_string($value) ? static::withTrashed()
                    ->whereRaw('LOWER(unit_code) = ?', [mb_strtolower($value)])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                    ->first() : null;

                if ($holder?->trashed()) {
                    $fail('This unit code belongs to a unit in the Trash. Restore that unit, or delete it permanently, first.');
                } elseif ($holder) {
                    $fail('This unit code is already taken.');
                }
            },
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
