<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'start',
        'end',
        'all_day',
        'is_holiday',
        'color',
        'reminder_at',
        'status',
        'recurrence'
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'reminder_at' => 'datetime',
        'all_day' => 'boolean',
        'is_holiday' => 'boolean',
        'recurrence' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // FullCalendar format এর জন্য
    public function toFullCalendarArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->start->toISOString(),
            'end' => $this->end?->toISOString(),
            'allDay' => $this->all_day,
            'color' => $this->is_holiday ? '#dc2626' : $this->color,
            'extendedProps' => [
                'description' => $this->description,
                'isHoliday' => $this->is_holiday,
                // Every calendar day a holiday covers (YYYY-MM-DD), so a 3-day
                // Eid is marked on all three days, not just the first.
                'dates' => $this->is_holiday ? $this->coveredDates() : [],
                'status' => $this->status,
                'reminderAt' => $this->reminder_at?->toISOString(),
            ]
        ];
    }

    // Scope for user events
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId)->orWhere('is_holiday', true);
    }

    public function scopeHolidays(Builder $query): Builder
    {
        return $query->where('is_holiday', true);
    }

    /** Longest span treated as one holiday; guards against a bad end date looping for years. */
    private const MAX_SPAN_DAYS = 366;

    /**
     * The last calendar day this event covers (inclusive).
     *
     * An end time of exactly 00:00 on a later day is the calendar-widget
     * convention for "up to, not including" — Apr 10 → Apr 13 00:00 means the
     * 10th to the 12th. Any other end time (such as the 23:59:59 holidays are
     * saved with) is read as an inclusive last day.
     */
    public function lastDay(): Carbon
    {
        $first = $this->start->copy()->startOfDay();

        if ($this->end === null) {
            return $first;
        }

        $last = $this->end->copy()->startOfDay();
        if ($this->end->isStartOfDay()) {
            $last->subDay();
        }

        if ($last->lessThan($first)) {
            return $first;
        }

        return $last->min($first->copy()->addDays(self::MAX_SPAN_DAYS - 1));
    }

    /** @return list<string> Every day covered, as YYYY-MM-DD. */
    public function coveredDates(): array
    {
        $dates = [];
        $last = $this->lastDay();

        for ($day = $this->start->copy()->startOfDay(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}