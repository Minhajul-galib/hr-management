<?php

namespace App\Models;

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
}