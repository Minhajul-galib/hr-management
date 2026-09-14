<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;
use Spatie\Permission\Models\Permission;
use App\Models\Setting;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->load('department'),
                'roles' => $request->user()?->getRoleNames(),
                'rolesWithMeta' => $request->user()?->roles()->select('id', 'name', 'display_name')->get(),
                // If user is Super System Admin, give them all permissions explicitly for frontend visibility
                'permissions' => $request->user()?->hasRole('Super System Admin')
                    ? Permission::pluck('name')
                    : $request->user()?->getAllPermissions()->pluck('name'),
            ],
            'impersonate' => [
                'is_impersonating' => $request->session()->has('impersonator_id'),
                'impersonator_id' => $request->session()->get('impersonator_id'),
            ],
            'notifications' => $request->user() ? [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'items' => $request->user()->unreadNotifications()->take(10)->get()->map(function ($notification) {
                    return [
                        'id' => $notification->id,
                        'type' => $notification->type,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                    ];
                })->toArray(),
            ] : null,
            'appSettings' => [
                'site_name' => Setting::get('site_name', 'HRIS'),
                'site_logo' => Setting::get('site_logo'),
                'site_logo_light' => Setting::get('site_logo_light', Setting::get('site_logo')),
                'site_logo_dark' => Setting::get('site_logo_dark', Setting::get('site_logo')),
                'site_favicon' => Setting::get('site_favicon'),
            ],
            'cliftoTalk' => fn () => \App\Services\CliftoTalk::allows($request->user())
                ? [
                    'enabled' => true,
                    'prefix' => \App\Services\CliftoTalk::prefix(),
                    'brand' => \App\Services\CliftoTalk::brand(),
                    'unread_total' => app(\App\Services\CliftoTalkService::class)->unreadTotalFor($request->user()),
                ]
                : [
                    'enabled' => false,
                    'prefix' => \App\Services\CliftoTalk::prefix(),
                    'brand' => \App\Services\CliftoTalk::brand(),
                    'unread_total' => 0,
                ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
