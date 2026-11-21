<?php

namespace App\Support\Holidays;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The one place that decides how a holiday is stored and when two holidays
 * clash, so the add/edit form, the Excel import and the Calendar page agree.
 *
 * A holiday is a whole-day calendar event flagged is_holiday. It starts at
 * 00:00 on its first day; a multi-day holiday ends at 23:59:59 on its last day.
 */
final class HolidayCalendar
{
    public const COLOR = '#dc2626';

    /** @return array<string, mixed> Attributes to save for a holiday running first → last day. */
    public static function attributes(CarbonInterface $first, ?CarbonInterface $last = null): array
    {
        $first = Carbon::instance($first)->startOfDay();
        $last = $last ? Carbon::instance($last)->startOfDay() : null;

        // A "last day" on or before the first day just means a one-day holiday.
        if ($last !== null && $last->lessThanOrEqualTo($first)) {
            $last = null;
        }

        return [
            'start' => $first,
            'end' => $last?->setTime(23, 59, 59),
            'all_day' => true,
            'is_holiday' => true,
            'color' => self::COLOR,
        ];
    }

    /**
     * Holidays that cover at least one day between $from and $to (inclusive).
     *
     * @return Collection<int, CalendarEvent>
     */
    public static function within(CarbonInterface $from, CarbonInterface $to, ?int $ignoreId = null): Collection
    {
        $from = Carbon::instance($from)->startOfDay();
        $to = Carbon::instance($to)->setTime(23, 59, 59);

        return CalendarEvent::holidays()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start', '<=', $to)
            ->where(fn ($query) => $query->where('start', '>=', $from)->orWhere('end', '>=', $from))
            ->orderBy('start')
            ->get()
            // The query is a coarse cut; lastDay() settles events whose end is
            // an exclusive midnight, which touch the window only by a hair.
            ->filter(fn (CalendarEvent $event) => $event->lastDay()->toDateString() >= $from->toDateString())
            ->values();
    }

    /** The holiday that already covers any day of first → last, if there is one. */
    public static function conflict(CarbonInterface $first, ?CarbonInterface $last = null, ?int $ignoreId = null): ?CalendarEvent
    {
        return self::within($first, $last ?? $first, $ignoreId)->first();
    }

    /** "16 Dec 2026" — how dates are written in messages. */
    public static function label(CarbonInterface $date): string
    {
        return $date->format('j M Y');
    }
}
