<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CalendarController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/credits', function () {
    return Inertia::render('Credits');
})->middleware(['auth'])->name('credits');

Route::get('/documentation', function () {
    abort_unless(auth()->user()->hasRole('Super System Admin') || auth()->user()->can('view documentation'), 403);
    return Inertia::render('Documentation');
})->middleware(['auth'])->name('documentation');

Route::get('/home', function () {
    return redirect()->route('dashboard');
})->middleware(['auth', 'verified']);

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('/calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store');
    Route::patch('/calendar/events/{event}', [CalendarController::class, 'update'])->name('calendar.events.update');
    Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy');
    Route::post('/calendar/holidays', [CalendarController::class, 'createHoliday'])->name('calendar.holidays.store');
    Route::get('/calendar/holidays/setup', [CalendarController::class, 'holidaySettings'])->name('calendar.holidays.setup');
    Route::post('/calendar/holidays/settings', [CalendarController::class, 'updateHolidaySettings'])->name('calendar.holidays.settings.update');
    Route::post('/calendar/holidays/import', [CalendarController::class, 'importHolidays'])->name('calendar.holidays.import');
    Route::post('/calendar/holidays/import-single', [CalendarController::class, 'importSingleHoliday'])->name('calendar.holidays.import_single');

    Route::prefix('impersonate')->name('impersonate.')->group(function () {
        Route::get('/start/{user}', [\App\Http\Controllers\ImpersonationController::class, 'start'])->name('start');
        Route::get('/stop', [\App\Http\Controllers\ImpersonationController::class, 'stop'])->name('stop');
    });

    Route::middleware('permission:access todo')->group(function () {
        Route::post('/calendar/todos', [CalendarController::class, 'storeTodo'])->name('calendar.todos.store');
        Route::patch('/calendar/todos/{todo}', [CalendarController::class, 'updateTodo'])->name('calendar.todos.update');
        Route::patch('/calendar/todos/order', [CalendarController::class, 'updateTodoOrder'])->name('calendar.todos.order');
        Route::post('/calendar/todos/order', [CalendarController::class, 'updateTodoOrder'])->name('calendar.todos.order.post');
        Route::delete('/calendar/todos/{todo}', [CalendarController::class, 'destroyTodo'])->name('calendar.todos.destroy');
    });

    Route::get('/todos', [\App\Http\Controllers\TodoController::class, 'index'])->middleware('permission:access todo')->name('todos.index');

    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');

    Route::middleware('permission:manage units|view units|create units|edit units|delete units')->group(function () {
        Route::resource('units', \App\Http\Controllers\UnitController::class);
        Route::post('/units/import', [\App\Http\Controllers\UnitController::class, 'import'])->name('units.import');
        Route::get('/units/template/download', [\App\Http\Controllers\UnitController::class, 'downloadTemplate'])->name('units.template');
        Route::post('/units/{id}/restore', [\App\Http\Controllers\UnitController::class, 'restore'])->name('units.restore');
        Route::delete('/units/{id}/force', [\App\Http\Controllers\UnitController::class, 'forceDestroy'])->name('units.force-destroy');
    });

    Route::middleware('permission:manage roles')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::patch('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.permissions.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware('permission:manage users')->group(function () {
        Route::resource('users', \App\Http\Controllers\UserController::class);
        Route::post('/users/{id}/restore', [\App\Http\Controllers\UserController::class, 'restore'])->name('users.restore');
        Route::delete('/users/{id}/force', [\App\Http\Controllers\UserController::class, 'forceDestroy'])->name('users.force-destroy');
    });

    Route::middleware('permission:manage notification settings')->group(function () {
        Route::get('/notification-settings', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notification-settings.index');
    });

    Route::middleware('permission:manage departments')->group(function () {
        Route::get('/departments', [\App\Http\Controllers\DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [\App\Http\Controllers\DepartmentController::class, 'store'])->name('departments.store');
        Route::delete('/departments/{department}', [\App\Http\Controllers\DepartmentController::class, 'destroy'])->name('departments.destroy');
    });

    Route::middleware('permission:manage settings')->group(function () {
        Route::get('/settings', [\App\Http\Controllers\SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [\App\Http\Controllers\SettingController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/clifto-talk.php';

require __DIR__.'/auth.php';
