# AI Coding Agent Instructions for ERP Analytics Dashboard

## Project Overview
This is a **Laravel 12 + Vue 3 ERP Analytics Dashboard** using **Inertia.js** for full-stack rendering. The system manages employee workflows including dashboard analytics, calendar events, todos (Kanban board), permissions, and real-time notifications with theme support.

## Architecture & Key Dependencies
- **Backend**: Laravel 12 with Spatie Permission (RBAC), Activity Logging, Excel exports
- **Frontend**: Vue 3 via Inertia.js with SSR, Vite build tool, Tailwind CSS (class-based dark mode)
- **Real-time**: Pusher + Laravel Echo for live notifications
- **UI Libraries**: ApexCharts (analytics), FullCalendar v6, Summernote (rich text), Lucide Vue icons
- **Data**: Eloquent ORM with Factories, Seeders, comprehensive migrations

## Critical Architectural Patterns

### 1. Inertia.js Data Flow (Server → Client)
Controllers return `Inertia::render('ViewName', ['data' => $value])`. Vue pages receive props automatically—no API calls needed for page load. Example:
```php
// DashboardController
return Inertia::render('Dashboard', [
    'stats' => $stats,
    'revenueData' => $revenueData,
    'taskProgressData' => $todoStats['taskProgressData'],
]);
```

### 2. Model Relationships & Scopes
- **User**: Has many CalendarEvents, Todos; uses Spatie `HasRoles` + `Impersonate` traits
- **CalendarEvent**: Belongs to User; has `toFullCalendarArray()` method for FullCalendar formatting
- **Todo**: Belongs to User (assignee) + User (assigned_by); includes status→color mapping methods
- Scopes: Use `forUser($userId)` to filter personal records before querying

### 3. Permission-Based Route Protection
Routes use `middleware('permission:access todo')` or `middleware('role:Super System Admin')`. Always check if features are permission-gated before adding new functionality. Example:
```php
Route::get('/todos', [TodoController::class, 'index'])
    ->middleware('permission:access todo')
    ->name('todos.index');
```

### 4. Status & Color Mappings
Models define color mappings as accessor methods (not in database):
```php
// Todo.php
public function getStatusColorAttribute(): string {
    return match($this->status) {
        'Approved' => 'bg-green-100 border-green-300 text-green-800',
        'Completed' => 'bg-emerald-100 border-emerald-300 text-emerald-800',
        // ...
    };
}
```
When adding new statuses, update both the migration enum AND the model accessor.

## Development Workflow

### Local Environment
```bash
# Install & setup
npm install && composer install

# Development (runs concurrent: Laravel server, queue listener, live logs, Vite)
npm run dev

# Production build (frontend assets + SSR bundle)
npm run build

# Testing
npm run test
```

### Build Chain
- **Package entry**: `resources/js/app.js` (Inertia bootstrap) and `ssr.js` (server rendering)
- **CSS**: Tailwind config at `tailwind.config.js` with CSS variables. Dark mode uses `darkMode: ['class']`—toggle `dark` class on `<html>` element
- **Drag-and-drop**: todo ordering uses `vuedraggable` (included in `optimizeDeps` to avoid Vite issues)

## Project-Specific Conventions

### File Organization
```
resources/js/
  Pages/        # Inertia page components (one per route)
  Components/   # Reusable Vue components (MetricCard, ChartCard, etc.)
  Layouts/      # Layout wrappers (authenticated vs guest)

app/Http/
  Controllers/  # One primary feature per controller (DashboardController, CalendarController)
  Requests/     # Form validation classes (auto-populated by Artisan)
  Middleware/   # Custom auth/permission middleware
  
app/Models/
  # Each model has: relationships, scopes, accessor methods for UI values
```

### Vue Component Patterns
- **Inertia Pages**: Accept `props` from controller; no data fetching in component
- **Reusable Components**: Accept data via props; emit events for parent handling
- **Dark Mode**: Use `v-if="darkMode"` or CSS classes; Tailwind `dark:` prefix handles styles
- **Icons**: Import from `lucide-vue-next` (e.g., `<Calendar />`, `<ChevronDown />`)
- **Charts**: ApexCharts series/categories passed as props from controller

### Database Conventions
- Timestamps: All models auto-include `created_at` + `updated_at`
- Enums: Status fields use database ENUMs matching model accessor keys
- Foreign Keys: Use `user_id`, `assigned_by` with `constrained()` in migrations
- Soft Deletes: Not currently used; add if archiving old records

## Integration Points & Critical Workflows

### Todo Drag-and-Drop
1. Frontend emits todo reorder event with new order array
2. Controller validates & updates `order` column via PATCH `/calendar/todos/order`
3. Status automatically updates when dragged between Kanban columns
4. **Note**: Route accepts both PATCH and POST (browser fallback in routes/web.php)

### Calendar Events & Holidays
- User events: `forUser($userId)` includes both personal + all holidays
- Holidays: Stored with `is_holiday = true`; admins import via `/calendar/holidays/import`
- Full Calendar events formatted via `toFullCalendarArray()` method
- Recurrence stored as JSON array in `recurrence` field

### Real-time Notifications
- Activity logged via Spatie `ActivityLog` on model changes
- Notifications use `Notifiable` trait; queued jobs handle mail/broadcast
- Echo broadcasts to `App.Models.User.{id}` private channel

### Authentication & Authorization
- Use `auth()->user()` in controllers
- Check permissions with `$user->hasPermissionTo('access todo')`
- `Super System Admin` role manages permissions for other roles
- Impersonation supported via `Lab404\Impersonate` trait (admin debug feature)

## Common Development Tasks

### Adding a New Feature/Page
1. Create migration (status enum, relationships as needed)
2. Create Model with relationships, scopes, accessor methods
3. Create Controller returning `Inertia::render()` with aggregated data
4. Add route in `routes/web.php` with permission middleware
5. Create Vue page in `resources/js/Pages/`
6. Build with `npm run build` to generate SSR bundle

### Adding a New Permission/Role
1. Create permission in admin panel or migration using Spatie
2. Attach to roles via `$role->givePermissionTo('permission_name')`
3. Gate routes with `middleware('permission:permission_name')`

### Styling
- Use Tailwind utility classes; avoid inline styles
- For theme-aware colors, reference Tailwind config CSS variables
- Dark mode: Prefix utilities with `dark:` (e.g., `dark:bg-gray-800`)
- Border radius: Use semantic `rounded-lg` (connects to CSS var in theme)

## Testing & Validation
- Tests: PHPUnit (Feature tests for routes, Unit tests for model logic)
- Form validation: Use `FormRequest` classes in `app/Http/Requests/`
- Client-side: Vue form components handle input errors from Inertia validation responses

## Key Files to Reference
- [DashboardController](app/Http/Controllers/DashboardController.php) – Data aggregation pattern
- [Todo model](app/Models/Todo.php) – Status/color mapping example
- [routes/web.php](routes/web.php) – Permission & middleware usage
- [Dashboard.vue](resources/js/Pages/Dashboard.vue) – Inertia page pattern with charts
- [tailwind.config.js](tailwind.config.js) – Theme colors & dark mode setup
