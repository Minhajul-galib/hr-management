<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidaySetting extends Model
{
    public const FRIDAY = 5;

    protected $fillable = ['all_fridays', 'weekday_off'];

    protected $casts = [
        'all_fridays' => 'boolean',
        'weekday_off' => 'array',
    ];

    public static function getCurrent(): self
    {
        return static::query()->first() ?? new static(['all_fridays' => false, 'weekday_off' => []]);
    }

    /**
     * Weekly off days as day numbers (0 = Sunday … 6 = Saturday).
     *
     * "All Fridays" used to be a flag separate from the weekday list; it is
     * folded in here so both old and new rows read the same way.
     *
     * @return list<int>
     */
    public function offDays(): array
    {
        $days = is_array($this->weekday_off) ? array_map('intval', $this->weekday_off) : [];

        if ($this->all_fridays) {
            $days[] = self::FRIDAY;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * Save the weekly off days. The legacy all_fridays flag is kept in step so
     * anything still reading it (the calendar page) sees the same answer.
     *
     * @param  list<int>  $days
     */
    public static function saveOffDays(array $days): self
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        $settings = static::query()->first() ?? new static;
        $settings->weekday_off = $days;
        $settings->all_fridays = in_array(self::FRIDAY, $days, true);
        $settings->save();

        return $settings;
    }
}
