<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $notifications = $user
            ? $user->notifications()->orderBy('created_at', 'desc')->paginate(20)->through(function ($n) {
                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'data' => $n->data,
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at,
                ];
            })
            : collect();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
        ]);
    }

    public function markAsRead(Request $request, string $notification)
    {
        $user = $request->user();
        if ($user) {
            $n = $user->notifications()->where('id', $notification)->first();
            if ($n && !$n->read_at) {
                $n->markAsRead();
                return response()->json([
                    'success' => true,
                    'message' => 'Notification marked as read.'
                ]);
            }
        }
        
        // For AJAX requests, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found or already read.'
            ]);
        }
        
        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->unreadNotifications->markAsRead();
            
            // For AJAX requests, return JSON response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'All notifications marked as read.'
                ]);
            }
        }
        
        return back()->with('success', 'All notifications marked as read.');
    }
}
