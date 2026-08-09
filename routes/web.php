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

// Booking Module Routes
Route::middleware('permission:manage bookings|view bookings')->group(function () {
    Route::resource('bookings', \App\Http\Controllers\BookingController::class);
    Route::resource('booking-types', \App\Http\Controllers\BookingTypeController::class);
});

Route::middleware('permission:manage yarn suppliers|view yarn suppliers|manage bookings|view bookings')->group(function () {
    Route::get('/yarn-suppliers/import', [\App\Http\Controllers\YarnSupplierController::class, 'importPage'])->name('yarn-suppliers.import.page');
    Route::post('/yarn-suppliers/import/preview', [\App\Http\Controllers\YarnSupplierController::class, 'importPreview'])->name('yarn-suppliers.import.preview');
    Route::post('/yarn-suppliers/import/confirm', [\App\Http\Controllers\YarnSupplierController::class, 'importConfirm'])->name('yarn-suppliers.import.confirm');
    Route::get('/yarn-suppliers/template/download', [\App\Http\Controllers\YarnSupplierController::class, 'downloadTemplate'])->name('yarn-suppliers.template');
    Route::delete('/yarn-suppliers/bulk', [\App\Http\Controllers\YarnSupplierController::class, 'bulkDestroy'])->name('yarn-suppliers.bulk-destroy');
    Route::post('/yarn-suppliers/bulk-restore', [\App\Http\Controllers\YarnSupplierController::class, 'bulkRestore'])->name('yarn-suppliers.bulk-restore');
    Route::delete('/yarn-suppliers/bulk-force', [\App\Http\Controllers\YarnSupplierController::class, 'bulkForceDestroy'])->name('yarn-suppliers.bulk-force-destroy');
    Route::post('/yarn-suppliers/{id}/restore', [\App\Http\Controllers\YarnSupplierController::class, 'restore'])->name('yarn-suppliers.restore');
    Route::delete('/yarn-suppliers/{id}/force', [\App\Http\Controllers\YarnSupplierController::class, 'forceDestroy'])->name('yarn-suppliers.force-destroy');
    Route::resource('yarn-suppliers', \App\Http\Controllers\YarnSupplierController::class);
});

Route::middleware('permission:manage merchandisers|view merchandisers|manage bookings|view bookings')->group(function () {
    Route::get('/merchant-teams/import', [\App\Http\Controllers\MerchantTeamController::class, 'importPage'])->name('merchant-teams.import.page');
    Route::post('/merchant-teams/import/preview', [\App\Http\Controllers\MerchantTeamController::class, 'importPreview'])->name('merchant-teams.import.preview');
    Route::post('/merchant-teams/import/confirm', [\App\Http\Controllers\MerchantTeamController::class, 'importConfirm'])->name('merchant-teams.import.confirm');
    Route::get('/merchant-teams/template/download', [\App\Http\Controllers\MerchantTeamController::class, 'downloadTemplate'])->name('merchant-teams.template');
    Route::delete('/merchant-teams/bulk', [\App\Http\Controllers\MerchantTeamController::class, 'bulkDestroy'])->name('merchant-teams.bulk-destroy');
    Route::post('/merchant-teams/bulk-restore', [\App\Http\Controllers\MerchantTeamController::class, 'bulkRestore'])->name('merchant-teams.bulk-restore');
    Route::delete('/merchant-teams/bulk-force', [\App\Http\Controllers\MerchantTeamController::class, 'bulkForceDestroy'])->name('merchant-teams.bulk-force-destroy');
    Route::post('/merchant-teams/{id}/restore', [\App\Http\Controllers\MerchantTeamController::class, 'restore'])->name('merchant-teams.restore');
    Route::delete('/merchant-teams/{id}/force', [\App\Http\Controllers\MerchantTeamController::class, 'forceDestroy'])->name('merchant-teams.force-destroy');
    Route::resource('merchant-teams', \App\Http\Controllers\MerchantTeamController::class);
});

Route::middleware('permission:manage yarn counts|view yarn counts|manage bookings|view bookings')->group(function () {
    Route::get('/yarn-counts/import', [\App\Http\Controllers\YarnCountController::class, 'importPage'])->name('yarn-counts.import.page');
    Route::post('/yarn-counts/import/preview', [\App\Http\Controllers\YarnCountController::class, 'importPreview'])->name('yarn-counts.import.preview');
    Route::post('/yarn-counts/import/confirm', [\App\Http\Controllers\YarnCountController::class, 'importConfirm'])->name('yarn-counts.import.confirm');
    Route::get('/yarn-counts/template/download', [\App\Http\Controllers\YarnCountController::class, 'downloadTemplate'])->name('yarn-counts.template');
    Route::delete('/yarn-counts/bulk', [\App\Http\Controllers\YarnCountController::class, 'bulkDestroy'])->name('yarn-counts.bulk-destroy');
    Route::post('/yarn-counts/bulk-restore', [\App\Http\Controllers\YarnCountController::class, 'bulkRestore'])->name('yarn-counts.bulk-restore');
    Route::delete('/yarn-counts/bulk-force', [\App\Http\Controllers\YarnCountController::class, 'bulkForceDestroy'])->name('yarn-counts.bulk-force-destroy');
    Route::post('/yarn-counts/{id}/restore', [\App\Http\Controllers\YarnCountController::class, 'restore'])->name('yarn-counts.restore');
    Route::delete('/yarn-counts/{id}/force', [\App\Http\Controllers\YarnCountController::class, 'forceDestroy'])->name('yarn-counts.force-destroy');
    Route::resource('yarn-counts', \App\Http\Controllers\YarnCountController::class);
});

Route::middleware('permission:manage fiber types|view fiber types|manage bookings|view bookings')->group(function () {
    Route::resource('fiber-types', \App\Http\Controllers\FiberTypeController::class);
});

// Procurement Followup Routes
Route::middleware('permission:manage procurement followup|view procurement followup|manage bookings|view bookings')->group(function () {
    Route::get('/procurement-followup', [\App\Http\Controllers\ProcurementFollowupController::class, 'index'])->name('procurement-followup.index');
});

Route::middleware('permission:export procurement followup|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/export', [\App\Http\Controllers\ProcurementFollowupController::class, 'export'])->name('procurement-followup.export');
});

Route::middleware('permission:view periodic view|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/periodic-view', [\App\Http\Controllers\ProcurementFollowupController::class, 'periodicView'])->name('procurement-followup.periodic-view');
    Route::get('/procurement-followup/periodic-view-data', [\App\Http\Controllers\ProcurementFollowupController::class, 'periodicViewData'])->name('procurement-followup.periodic-view-data');
});

Route::middleware('permission:view periodic view rcv date|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/periodic-view-rcv-date', [\App\Http\Controllers\ProcurementFollowupController::class, 'periodicViewRcvDate'])->name('procurement-followup.periodic-view-rcv-date');
    Route::get('/procurement-followup/periodic-view-rcv-date-data', [\App\Http\Controllers\ProcurementFollowupController::class, 'periodicViewRcvDateData'])->name('procurement-followup.periodic-view-rcv-date-data');
});

Route::middleware('permission:view pi wise analysis|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/pi-wise-analysis', [\App\Http\Controllers\ProcurementFollowupController::class, 'piWiseAnalysis'])->name('procurement-followup.pi-wise-analysis');
    Route::get('/procurement-followup/pi-wise-analysis-data', [\App\Http\Controllers\ProcurementFollowupController::class, 'piWiseAnalysisData'])->name('procurement-followup.pi-wise-analysis-data');
});

Route::middleware('permission:view supplier order breakdown|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/supplier-breakdown', [\App\Http\Controllers\ProcurementFollowupController::class, 'supplierOrderBreakdown'])->name('procurement-followup.supplier-breakdown');
});

Route::middleware('permission:create procurement followup|manage procurement followup|manage bookings')->group(function () {
    Route::post('/procurement-followup', [\App\Http\Controllers\ProcurementFollowupController::class, 'store'])->name('procurement-followup.store');
});

Route::middleware('permission:import procurement followup|manage procurement followup|manage bookings')->group(function () {
    Route::get('/procurement-followup/import', [\App\Http\Controllers\ProcurementFollowupImportController::class, 'page'])->name('procurement-followup.import.page');
    Route::get('/procurement-followup/import/template', [\App\Http\Controllers\ProcurementFollowupImportController::class, 'downloadTemplate'])->name('procurement-followup.import.template');
    Route::post('/procurement-followup/import/preview', [\App\Http\Controllers\ProcurementFollowupImportController::class, 'preview'])->name('procurement-followup.import.preview');
    Route::post('/procurement-followup/import/confirm', [\App\Http\Controllers\ProcurementFollowupImportController::class, 'confirm'])->name('procurement-followup.import.confirm');
    Route::get('/procurement-followup/import/progress/{importId}', [\App\Http\Controllers\ProcurementFollowupImportController::class, 'progress'])->name('procurement-followup.import.progress');
});

Route::middleware('permission:edit procurement followup|manage procurement followup|manage bookings')->group(function () {
    Route::patch('/procurement-followup/{id}', [\App\Http\Controllers\ProcurementFollowupController::class, 'update'])->name('procurement-followup.update');
});

Route::middleware('permission:delete procurement followup|manage procurement followup|manage bookings')->group(function () {
    Route::delete('/procurement-followup/bulk', [\App\Http\Controllers\ProcurementFollowupController::class, 'bulkDestroy'])->name('procurement-followup.bulk-destroy');
    Route::post('/procurement-followup/bulk-restore', [\App\Http\Controllers\ProcurementFollowupController::class, 'bulkRestore'])->name('procurement-followup.bulk-restore');
    Route::delete('/procurement-followup/bulk-force', [\App\Http\Controllers\ProcurementFollowupController::class, 'bulkForceDestroy'])->name('procurement-followup.bulk-force-destroy');

    Route::delete('/procurement-followup/{id}', [\App\Http\Controllers\ProcurementFollowupController::class, 'destroy'])->name('procurement-followup.destroy');
    Route::post('/procurement-followup/{id}/restore', [\App\Http\Controllers\ProcurementFollowupController::class, 'restore'])->name('procurement-followup.restore');
    Route::delete('/procurement-followup/{id}/force', [\App\Http\Controllers\ProcurementFollowupController::class, 'forceDestroy'])->name('procurement-followup.force-destroy');
});

require __DIR__.'/auth.php';
