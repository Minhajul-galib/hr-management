<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\HolidaySetting;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Notifications\CalendarReminderNotification;
use App\Services\NotificationService;
use App\Support\HtmlSanitizer;
use App\Support\MasterData\MasterDataRegistry;

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

        $offDays = HolidaySetting::getCurrent()->offDays();
        $holidaySettings = [
            'all_fridays' => in_array(HolidaySetting::FRIDAY, $offDays, true),
            'weekday_off' => $offDays,
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
        // Holidays belong to whoever manages holidays, not to whoever typed them in
        // (the old check named a "Super Admin" role that doesn't exist, so nobody could edit one).
        if ($event->is_holiday) {
            abort_unless(auth()->user()->canAny(MasterDataRegistry::allowing(MasterDataRegistry::HOLIDAYS, 'edit')), 403, 'You do not have permission to edit holidays');
        } elseif ($event->user_id !== auth()->id()) {
            abort(403);
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
        $mayDelete = $event->is_holiday
            ? auth()->user()->canAny(MasterDataRegistry::allowing(MasterDataRegistry::HOLIDAYS, 'delete'))
            : $event->user_id === auth()->id();

        abort_unless($mayDelete, 403);

        $event->delete();

        return back()->with('success', 'Event deleted successfully!');
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
            // Description is rich text rendered with v-html for whoever the
            // todo is assigned to, so it's sanitized on the way in — not
            // just escaped in the summernote editor, which a direct API
            // call bypasses entirely.
            'description' => HtmlSanitizer::clean($validated['description'] ?? null),
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

        if (array_key_exists('description', $validated)) {
            $validated['description'] = HtmlSanitizer::clean($validated['description']);
        }

        $todo->update($validated);

        return back()->with('success', 'Todo updated successfully!');
    }

    public function updateTodoOrder(Request $request)
    {
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
