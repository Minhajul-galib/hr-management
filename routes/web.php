<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\MasterDataController;
use App\Support\MasterData\MasterDataRegistry;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\HolidayController;
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

    // Holidays: each action has its own permission; `manage holidays` is full access.
    Route::prefix('calendar/holidays')->name('calendar.holidays.')->group(function () {
        $can = fn (string $action) => 'permission:'.implode('|', MasterDataRegistry::allowing(MasterDataRegistry::HOLIDAYS, $action));

        Route::get('/setup', [HolidayController::class, 'index'])
            ->middleware('permission:'.implode('|', MasterDataRegistry::holidayPermissionNames()))->name('setup');
        Route::get('/template', [HolidayController::class, 'template'])->middleware($can('import'))->name('template');
        Route::post('/', [HolidayController::class, 'store'])->middleware($can('create'))->name('store');
        Route::post('/settings', [HolidayController::class, 'updateSettings'])->middleware($can('edit'))->name('settings.update');
        Route::post('/import', [HolidayController::class, 'import'])->middleware($can('import'))->name('import');
        Route::put('/{holiday}', [HolidayController::class, 'update'])->middleware($can('edit'))->whereNumber('holiday')->name('update');
        Route::delete('/{holiday}', [HolidayController::class, 'destroy'])->middleware($can('delete'))->whereNumber('holiday')->name('destroy');
    });

    Route::prefix('impersonate')->name('impersonate.')->group(function () {
        // POST, not GET: this changes who the caller is authenticated as, so
        // it must go through CSRF verification like any other state change —
        // a GET route here would let a plain <img>/<a> on another site force
        // an admin's browser to switch sessions with no confirmation.
        Route::post('/start/{user}', [\App\Http\Controllers\ImpersonationController::class, 'start'])->name('start');
        Route::post('/stop', [\App\Http\Controllers\ImpersonationController::class, 'stop'])->name('stop');
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

    // Units: any unit permission opens the list; each change needs its own permission
    // (`manage units` is the full-access one), matching what the Units page shows.
    // Static paths (/units/create, /units/import) are registered before /units/{unit}.
    Route::middleware('permission:manage units|create units')->group(function () {
        Route::get('/units/create', [\App\Http\Controllers\UnitController::class, 'create'])->name('units.create');
        Route::post('/units', [\App\Http\Controllers\UnitController::class, 'store'])->name('units.store');
        Route::post('/units/import', [\App\Http\Controllers\UnitController::class, 'import'])->name('units.import');
        Route::get('/units/template/download', [\App\Http\Controllers\UnitController::class, 'downloadTemplate'])->name('units.template');
    });
    Route::middleware('permission:manage units|edit units')->group(function () {
        Route::get('/units/{unit}/edit', [\App\Http\Controllers\UnitController::class, 'edit'])->name('units.edit');
        Route::match(['put', 'patch'], '/units/{unit}', [\App\Http\Controllers\UnitController::class, 'update'])->name('units.update');
    });
    Route::middleware('permission:manage units|delete units')->group(function () {
        Route::delete('/units/{unit}', [\App\Http\Controllers\UnitController::class, 'destroy'])->name('units.destroy');
        Route::post('/units/{id}/restore', [\App\Http\Controllers\UnitController::class, 'restore'])->name('units.restore');
        Route::delete('/units/{id}/force', [\App\Http\Controllers\UnitController::class, 'forceDestroy'])->name('units.force-destroy');
    });
    Route::middleware('permission:manage units|view units|create units|edit units|delete units')->group(function () {
        Route::get('/units', [\App\Http\Controllers\UnitController::class, 'index'])->name('units.index');
        Route::get('/units/{unit}', [\App\Http\Controllers\UnitController::class, 'show'])->name('units.show');
    });

    Route::middleware('permission:view audits|manage audits|update audit cap')->prefix('audits')->name('audits.')->group(function () {
        Route::get('/', [AuditController::class, 'index'])->name('index');

        Route::middleware('permission:manage audits')->group(function () {
            Route::get('/create', [AuditController::class, 'create'])->name('create');
            Route::post('/', [AuditController::class, 'store'])->name('store');
            Route::get('/{audit}/edit', [AuditController::class, 'edit'])->name('edit');
            Route::put('/{audit}', [AuditController::class, 'update'])->name('update');
            Route::delete('/{audit}', [AuditController::class, 'destroy'])->name('destroy');
            Route::post('/{audit}/reopen', [AuditController::class, 'reopen'])->name('reopen');
        });

        Route::get('/{audit}', [AuditController::class, 'show'])->name('show');
        Route::get('/{audit}/print', [AuditController::class, 'print'])->name('print');
        Route::patch('/findings/{finding}/cap', [AuditController::class, 'updateCap'])
            ->middleware('permission:update audit cap|manage audits')
            ->name('findings.cap');
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

    // HR master data (departments, designations, grades, ...). Each master gets its own
    // routes and permissions: `designations.store` needs `create designations` or full
    // access (`manage designations`), and so on; see App\Support\MasterData\MasterDataRegistry.
    foreach (MasterDataRegistry::all() as $key => $master) {
        $permission = $master['permission'];
        // "permission:a|b" lets through anyone holding either: the action's own permission or full access.
        $can = fn (string $action) => 'permission:'.implode('|', MasterDataRegistry::allowing($permission, $action));

        Route::prefix("master-data/{$key}")->name("{$key}.")->group(function () use ($key, $master, $can) {
            // Any permission on the master opens its screen; the page then shows only what the role may do.
            Route::get('/', [MasterDataController::class, 'index'])
                ->defaults('type', $key)
                ->middleware('permission:'.implode('|', MasterDataRegistry::opening($master)))
                ->name('index');

            Route::post('/', [MasterDataController::class, 'store'])->defaults('type', $key)->middleware($can('create'))->name('store');
            Route::put('/{id}', [MasterDataController::class, 'update'])->defaults('type', $key)->middleware($can('edit'))->whereNumber('id')->name('update');
            Route::delete('/{id}', [MasterDataController::class, 'destroy'])->defaults('type', $key)->middleware($can('delete'))->whereNumber('id')->name('destroy');

            if (isset($master['import'])) {
                Route::get('/export', [MasterDataController::class, 'export'])->defaults('type', $key)->middleware($can('export'))->name('export');

                Route::middleware($can('import'))->group(function () use ($key) {
                    Route::get('/template', [MasterDataController::class, 'template'])->defaults('type', $key)->name('template');
                    Route::post('/import', [MasterDataController::class, 'import'])->defaults('type', $key)->name('import');
                    Route::post('/import/preview', [MasterDataController::class, 'preview'])->defaults('type', $key)->name('import.preview');
                    Route::post('/import/check', [MasterDataController::class, 'check'])->defaults('type', $key)->name('import.check');
                });
            }
        });
    }

    Route::middleware('permission:manage settings')->group(function () {
        Route::get('/settings', [\App\Http\Controllers\SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [\App\Http\Controllers\SettingController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/clifto-talk.php';

require __DIR__.'/auth.php';
