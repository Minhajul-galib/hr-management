<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\HolidaySetting;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Notifications\CalendarReminderNotification;
use App\Services\NotificationService;

class CalendarController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasRole('Super System Admin') || auth()->user()->can('view calendar'), 403);

        // Ensure we do not pass duplicate events to the frontend (sometimes scope joins/duplicates may occur)
        $events = CalendarEvent::forUser(auth()->id())
            ->orderBy('start')
            ->get()
            ->unique('id')
            ->values()
            ->map(fn($event) => $event->toFullCalendarArray());

        $todos = Todo::forUser(auth()->id())
            ->orderBy('order')
            ->orderBy('created_at')
            ->get();

        $settings = HolidaySetting::getCurrent();
        $holidaySettings = [
            'all_fridays' => (bool)($settings->all_fridays ?? false),
            'weekday_off' => is_array($settings->weekday_off) ? $settings->weekday_off : [],
        ];

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'todos' => $todos,
            'upcomingEvents' => CalendarEvent::forUser(auth()->id())
                ->where('start', '>=', now())
                ->where('start', '<=', now()->addDays(7))
                ->orderBy('start')
                ->limit(5)
                ->get(),
            'holidaySettings' => $holidaySettings,
            // Whether current user can access the Todo system (managed via Permission Matrix)
            'canAccessTodo' => auth()->user()->can('access todo'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start' => 'required|date',
            'end' => 'nullable|date|after:start',
            'all_day' => 'boolean',
            'color' => 'nullable|string|max:7',
            'reminder_at' => 'nullable|date',
        ]);

        $event = CalendarEvent::create([
            'user_id' => auth()->id(),
            ...$validated
        ]);

        return back()->with('success', 'Event created successfully!');
    }

    public function update(Request $request, CalendarEvent $event)
    {
        // Check if user owns the event or it's a holiday (only admin can edit holidays)
        if ($event->user_id !== auth()->id() && !$event->is_holiday) {
            abort(403);
        }

        if ($event->is_holiday && !auth()->user()->hasRole('Super Admin')) {
            abort(403, 'Only admins can edit holidays');
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start' => 'sometimes|date',
            'end' => 'nullable|date|after:start',
            'all_day' => 'boolean',
            'color' => 'nullable|string|max:7',
            'reminder_at' => 'nullable|date',
            'status' => 'sometimes|in:pending,completed,cancelled'
        ]);

        $event->update($validated);

        return back()->with('success', 'Event updated successfully!');
    }

    public function destroy(CalendarEvent $event)
    {
        if ($event->user_id !== auth()->id() && !auth()->user()->hasRole('Super Admin')) {
            abort(403);
        }

        $event->delete();

        return back()->with('success', 'Event deleted successfully!');
    }

    // Holiday management (Admin only)
    public function createHoliday(Request $request)
    {
        // Allow Super Admin variants, MIS and HR roles to create holidays
        if (!auth()->user()->can('manage holidays')) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start' => 'required|date',
            'end' => 'nullable|date|after:start',
            'all_day' => 'boolean',
        ]);

        CalendarEvent::create([
            'user_id' => auth()->id(),
            'is_holiday' => true,
            'color' => '#dc2626', // Red color for holidays
            ...$validated
        ]);

        return back()->with('success', 'Holiday created successfully!');
    }
    
    // Holidays Settings Page
    public function holidaySettings()
    {
        if (!auth()->user()->can('manage holidays')) {
            abort(403);
        }
        
        $settings = HolidaySetting::getCurrent();
        $data = [
            'all_fridays' => (bool)($settings->all_fridays ?? false),
            'weekday_off' => is_array($settings->weekday_off) ? $settings->weekday_off : [],
        ];
        
        return Inertia::render('Holidays/Setup', [
            'settings' => $data,
            'canManage' => true,
        ]);
    }
    
    // Update Holidays Settings
    public function updateHolidaySettings(Request $request)
    {
        if (!auth()->user()->can('manage holidays')) {
            abort(403);
        }
        
        $validated = $request->validate([
            'all_fridays' => 'required|boolean',
            'weekday_off' => 'array',
            'weekday_off.*' => 'integer|min:0|max:6',
        ]);
        
        $settings = HolidaySetting::query()->first();
        if (!$settings) {
            $settings = new HolidaySetting();
        }
        $settings->all_fridays = $validated['all_fridays'];
        $settings->weekday_off = $validated['weekday_off'] ?? [];
        $settings->save();
        
        return back()->with('success', 'Holiday settings updated successfully!');
    }
    
    // Bulk Import Holidays from CSV
    public function importHolidays(Request $request)
    {
        if (!auth()->user()->can('manage holidays')) {
            abort(403);
        }
        
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);
        
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return back()->with('error', 'Failed to read uploaded file.');
        }
        
        $created = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 0) {
                continue;
            }
            // Expecting date,title or date only
            $date = trim($row[0] ?? '');
            $title = trim($row[1] ?? 'Holiday');
            
            if (!$date) {
                continue;
            }
            
            try {
                $start = Carbon::parse($date)->startOfDay();
            } catch (\Throwable $e) {
                continue;
            }
            
            // Avoid duplicates: if a holiday exists on this date
            $exists = CalendarEvent::query()
                ->whereDate('start', $start->toDateString())
                ->where('is_holiday', true)
                ->exists();
            
            if ($exists) {
                continue;
            }
            
            CalendarEvent::create([
                'user_id' => auth()->id(),
                'title' => $title,
                'description' => null,
                'start' => $start,
                'end' => null,
                'all_day' => true,
                'is_holiday' => true,
                'color' => '#dc2626',
            ]);
            $created++;
        }
        fclose($handle);
        
        return back()->with('success', "Imported {$created} holidays successfully.");
    }

    // Import a single holiday (from a date + title form)
    public function importSingleHoliday(Request $request)
    {
        if (!auth()->user()->can('manage holidays')) {
            abort(403);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'title' => 'nullable|string|max:255',
        ]);

        try {
            $start = Carbon::parse($validated['date'])->startOfDay();
        } catch (\Throwable $e) {
            return back()->with('error', 'Invalid date provided.');
        }

        $exists = CalendarEvent::query()
            ->whereDate('start', $start->toDateString())
            ->where('is_holiday', true)
            ->exists();

        if ($exists) {
            return back()->with('error', 'A holiday already exists on that date.');
        }

        CalendarEvent::create([
            'user_id' => auth()->id(),
            'title' => $validated['title'] ?: 'Holiday',
            'description' => null,
            'start' => $start,
            'end' => null,
            'all_day' => true,
            'is_holiday' => true,
            'color' => '#dc2626',
        ]);

        return back()->with('success', 'Holiday added successfully.');
    }

    // Todo management
    public function storeTodo(Request $request)
    {
        $validated = $request->validate([
            'task' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:self,assign',
            'user_id' => 'nullable|required_if:type,assign|exists:users,id',
            'status' => 'nullable|in:Assigned,Processing,Testing,Submitted,Approved,Completed',
            'priority' => 'nullable|in:low,medium,high',
            'due_date' => 'nullable|date',
            'reminder_at' => 'nullable|date',
            'category' => 'nullable|string|max:100',
        ]);

        $userId = $validated['type'] === 'assign' ? $validated['user_id'] : auth()->id();
        $assignedBy = $validated['type'] === 'assign' ? auth()->id() : null;

        $maxOrder = Todo::where('user_id', $userId)->max('order') ?? 0;

        $todo = Todo::create([
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
            'order' => $maxOrder + 1,
            'task' => $validated['task'],
            'description' => $validated['description'],
            'status' => $validated['status'] ?? 'Assigned',
            'type' => $validated['type'] === 'assign' ? 'public' : 'personal',
            'priority' => $validated['priority'] ?? 'medium',
            'due_date' => $validated['due_date'],
            'reminder_at' => $validated['reminder_at'],
            'category' => $validated['category'],
        ]);

        if ($validated['type'] === 'assign') {
            $recipient = User::find($userId);
            if ($recipient) {
                NotificationService::sendTodoAssignmentNotification(
                    $recipient,
                    $todo->id,
                    $todo->task,
                    auth()->user()
                );
            }
        }

        // Get updated todos for the response
        $todos = Todo::where(function ($query) {
                $query->where('user_id', auth()->id())
                      ->orWhere('assigned_by', auth()->id());
            })
            ->orderBy('order')
            ->orderBy('created_at')
            ->with(['user', 'assignedBy'])
            ->get();

        return back()->with([
            'success' => 'Todo created successfully!',
            'todos' => $todos
        ]);
    }

    public function updateTodo(Request $request, Todo $todo)
    {
        if ($todo->user_id !== auth()->id() && $todo->assigned_by !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'task' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:Assigned,Processing,Testing,Submitted,Approved,Completed',
            'type' => 'sometimes|in:public,personal,confidential',
            'priority' => 'sometimes|in:low,medium,high',
            'due_date' => 'nullable|date',
            'reminder_at' => 'nullable|date',
            'category' => 'nullable|string|max:100',
        ]);

        $todo->update($validated);

        return back()->with('success', 'Todo updated successfully!');
    }

    public function updateTodoOrder(Request $request)
    {
        // Temporary debug logging to confirm incoming payload and HTTP method
        Log::info('updateTodoOrder called', ['method' => $request->method(), 'payload' => $request->all()]);

        $validated = $request->validate([
            'todos' => 'required|array',
            'todos.*.id' => 'required|exists:todos,id',
            'todos.*.order' => 'required|integer',
        ]);

        foreach ($validated['todos'] as $todoData) {
            Todo::where('id', $todoData['id'])
                ->where(function ($query) {
                    $query->where('user_id', auth()->id())
                          ->orWhere('assigned_by', auth()->id());
                })
                ->update(['order' => $todoData['order']]);
        }

        return response()->json(['success' => true]);
    }

    public function destroyTodo(Todo $todo)
    {
        if ($todo->user_id !== auth()->id() && $todo->assigned_by !== auth()->id()) {
            abort(403);
        }

        $todo->delete();

        return back()->with('success', 'Todo deleted successfully!');
    }
}
