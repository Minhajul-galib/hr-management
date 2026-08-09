<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CalendarEvent;
use App\Models\Todo;
use App\Models\NotificationSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Notifications\CalendarReminderNotification;

class SendReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders for calendar events and todos';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();
        $currentMinute = $now->format('Y-m-d H:i:00');
        
        $this->info("Checking for reminders at: {$currentMinute}");

        // Check for event reminders
        $eventReminders = CalendarEvent::whereNotNull('reminder_at')
            ->where('reminder_at', $currentMinute)
            ->with('user')
            ->get();

        foreach ($eventReminders as $event) {
            // Check if calendar reminder notifications are enabled
            if (NotificationSetting::isEnabled('calendar_reminder')) {
                $this->sendEventReminder($event);
            }
        }

        // Check for todo reminders
        $todoReminders = Todo::whereNotNull('reminder_at')
            ->where('reminder_at', $currentMinute)
            ->where('status', '!=', 'Completed')
            ->with('user')
            ->get();

        foreach ($todoReminders as $todo) {
            // Check if calendar reminder notifications are enabled (todos use same type)
            if (NotificationSetting::isEnabled('calendar_reminder')) {
                $this->sendTodoReminder($todo);
            }
        }

        $totalReminders = $eventReminders->count() + $todoReminders->count();
        
        if ($totalReminders > 0) {
            $this->info("Sent {$totalReminders} reminders successfully!");
            Log::info("Sent {$totalReminders} reminders", [
                'events' => $eventReminders->count(),
                'todos' => $todoReminders->count(),
                'timestamp' => $now
            ]);
        } else {
            $this->info("No reminders to send at this time.");
        }

        return 0;
    }

    private function sendEventReminder(CalendarEvent $event)
    {
        $message = "Event Reminder: '{$event->title}' starts at {$event->start->format('Y-m-d H:i')}";

        Log::info('Event Reminder Sent', [
            'user_id' => $event->user_id,
            'user_name' => $event->user->name,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'event_start' => $event->start,
            'message' => $message
        ]);

        $this->line("📅 Event reminder sent to {$event->user->name}: {$event->title}");

        $event->user->notify(new CalendarReminderNotification([
            'user_id' => $event->user_id,
            'type' => 'calendar',
            'title' => 'Calendar Reminder',
            'body' => $message,
            'event_id' => $event->id,
            'reminder_at' => $event->reminder_at?->toIso8601String() ?? now()->toIso8601String(),
        ]));
    }

    private function sendTodoReminder(Todo $todo)
    {
        $message = "Todo Reminder: '{$todo->task}' is due";
        if ($todo->due_date) {
            $message .= " on {$todo->due_date->format('Y-m-d')}";
        }
        
        Log::info('Todo Reminder Sent', [
            'user_id' => $todo->user_id,
            'user_name' => $todo->user->name,
            'todo_id' => $todo->id,
            'todo_task' => $todo->task,
            'todo_due_date' => $todo->due_date,
            'message' => $message
        ]);

        $this->line("✅ Todo reminder sent to {$todo->user->name}: {$todo->task}");

        $todo->user->notify(new CalendarReminderNotification([
            'user_id' => $todo->user_id,
            'type' => 'todo',
            'title' => 'Todo Reminder',
            'body' => $message,
            'todo_id' => $todo->id,
            'reminder_at' => $todo->reminder_at?->toIso8601String() ?? now()->toIso8601String(),
        ]));
    }
}
