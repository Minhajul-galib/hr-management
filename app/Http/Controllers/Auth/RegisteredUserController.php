<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'designations' => $this->activeDesignations()->get(['id', 'code', 'title']),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'phone' => ['required','string','regex:/^[0-9+()\-\s]{7,20}$/','unique:users,phone'],
            // Picked from the Designations master list. Only required once the Editor
            // has entered at least one, so registration still works on an empty list.
            'designation_id' => [
                Rule::requiredIf(fn () => $this->activeDesignations()->exists()),
                'nullable',
                Rule::exists('designations', 'id')->where('is_active', true),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'designation_id' => $request->designation_id,
            'password' => Hash::make($request->password),
        ]);

        // Assign default "Applicant" role to new users
        $applicantRole = Role::firstOrCreate(['name' => 'Applicant']);
        $user->assignRole($applicantRole);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /**
     * Designations an applicant may choose, most senior first.
     */
    private function activeDesignations()
    {
        return Designation::where('is_active', true)->orderBy('serial')->orderBy('title');
    }
}
