<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use Inertia\Inertia;
use Illuminate\Support\Facades\Session;

class ImpersonationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Share impersonation status with Inertia
        Inertia::share([
            'is_impersonating' => Session::has('impersonator_id'),
            'impersonator_id' => Session::get('impersonator_id'),
        ]);

        return $next($request);
    }
}
