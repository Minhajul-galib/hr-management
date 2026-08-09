<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'task',
        'description',
        'status',
        'type',
        'order',
        'priority',
        'due_date',
        'reminder_at',
        'category',
        'assigned_by'
    ];

    protected $casts = [
        'status' => 'string',
        'due_date' => 'datetime',
        'reminder_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // Scope for user todos
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Priority color mapping
    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            'high' => 'bg-red-100 border-red-300 text-red-800',
            'medium' => 'bg-yellow-100 border-yellow-300 text-yellow-800',
            'low' => 'bg-green-100 border-green-300 text-green-800',
            default => 'bg-gray-100 border-gray-300 text-gray-800'
        };
    }

    // Status color mapping
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Assigned' => 'bg-gray-100 border-gray-300 text-gray-800',
            'Processing' => 'bg-blue-100 border-blue-300 text-blue-800',
            'Testing' => 'bg-amber-100 border-amber-300 text-amber-800',
            'Submitted' => 'bg-purple-100 border-purple-300 text-purple-800',
            'Approved' => 'bg-green-100 border-green-300 text-green-800',
            'Completed' => 'bg-emerald-100 border-emerald-300 text-emerald-800',
            default => 'bg-gray-100 border-gray-300 text-gray-800'
        };
    }
}