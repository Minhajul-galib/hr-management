<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a user.
     */
    public function start(User $user, Request $request)
    {
        // 1. Security: Only Super System Admin can start impersonation
        if (!Auth::user()->hasRole('Super System Admin')) {
            abort(403, 'Unauthorized. Only Super System Admin can impersonate users.');
        }

        // 2. Prevent impersonating self
        if (Auth::id() === $user->id) {
            return back()->with('error', 'You cannot impersonate yourself.');
        }

        // 3. Store the original admin ID in session
        Session::put('impersonator_id', Auth::id());

        // 4. Log the activity
        activity()
            ->performedOn($user)
            ->causedBy(Auth::user())
            ->log("Started impersonating user: {$user->name} (ID: {$user->id})");

        // 5. Login as the target user. Regenerate the session ID on this
        // identity switch (keeping the data, including impersonator_id, set
        // above) so a session fixed before login can't ride along into it.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', "You are now impersonating {$user->name}");
    }

    /**
     * Stop impersonating and return to original admin account.
     */
    public function stop(Request $request)
    {
        // 1. Check if we are currently impersonating
        if (!Session::has('impersonator_id')) {
            return redirect()->route('dashboard');
        }

        $originalAdminId = Session::get('impersonator_id');
        $originalAdmin = User::findOrFail($originalAdminId);
        $currentUser = Auth::user();

        // 2. Clear impersonation session
        Session::forget('impersonator_id');

        // 3. Log the activity
        activity()
            ->performedOn($currentUser)
            ->causedBy($originalAdmin)
            ->log("Stopped impersonating user: {$currentUser->name}");

        // 4. Login back as original admin
        Auth::login($originalAdmin);
        $request->session()->regenerate();

        return redirect()->route('users.index')->with('success', 'Returned to Admin account.');
    }
}
