<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TodoController extends Controller
{
    /**
     * Show a listing of the user's todos in a dedicated page.
     */
    public function index()
    {
        $todos = Todo::where(function ($query) {
                $query->where('user_id', auth()->id())
                      ->orWhere('assigned_by', auth()->id());
            })
            ->orderBy('order')
            ->orderBy('created_at')
            ->with(['user', 'assignedBy'])
            ->get();

        $users = \App\Models\User::select('id', 'name')->get();

        return Inertia::render('Todos/Index', [
            'todos' => $todos,
            'users' => $users,
            'canAccessTodo' => auth()->user()->can('access todo'),
        ]);
    }
}
