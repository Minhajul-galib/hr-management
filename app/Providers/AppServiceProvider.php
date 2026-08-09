<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Models\User;
use App\Observers\UserObserver;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Register User Observer for automatic role assignment
        User::observe(UserObserver::class);

        // সুপার এডমিনকে সব সময় সত্য (true) রিটার্ন করবে
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('Super System Admin') ? true : null;
        });

        // Ensure 'manage holidays' permission exists and is assigned to Super System Admin by default
        try {
            $perm = Permission::firstOrCreate(['name' => 'manage holidays']);
            $superAdmin = Role::where('name', 'Super System Admin')->first();
            if ($superAdmin && !$superAdmin->hasPermissionTo($perm)) {
                $superAdmin->givePermissionTo($perm);
            }
        } catch (\Throwable $e) {
            // silently ignore if roles/permissions tables not ready yet
        }
    }
}
