<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Broadcasting\PrivateChannel;

class CalendarReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload)
    {
    }

    public function via($notifiable): array
    {
        return ['broadcast', 'database'];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => $this->payload['title'] ?? 'Reminder',
            'body' => $this->payload['body'] ?? '',
            'type' => $this->payload['type'] ?? 'calendar',
            'event_id' => $this->payload['event_id'] ?? null,
            'todo_id' => $this->payload['todo_id'] ?? null,
            'reminder_at' => $this->payload['reminder_at'] ?? now()->toIso8601String(),
        ]);
    }

    public function toDatabase($notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => $this->payload['title'] ?? 'Reminder',
            'body' => $this->payload['body'] ?? '',
            'type' => $this->payload['type'] ?? 'calendar',
            'event_id' => $this->payload['event_id'] ?? null,
            'todo_id' => $this->payload['todo_id'] ?? null,
            'reminder_at' => $this->payload['reminder_at'] ?? now()->toIso8601String(),
        ]);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . ($this->payload['user_id'] ?? ''))];
    }
}
