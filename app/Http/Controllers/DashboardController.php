<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $isSuperAdmin = $user->hasRole('Super System Admin');
        $isImpersonating = $request->session()->has('impersonator_id');

        // A new registrant is an Applicant with no permissions yet. Rather than a 403,
        // they get a thank-you page. If an admin later grants the Applicant role
        // `view dashboard`, they get the real dashboard like anyone else.
        if (! $isSuperAdmin && $user->hasRole('Applicant') && ! $user->can('view dashboard')) {
            $user->loadMissing('designation:id,designation_category_id,code,title', 'designation.category:id,name');

            return Inertia::render('Dashboard/Applicant', [
                'applicant' => [
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'designation' => $user->designation?->title,
                    'designation_category' => $user->designation?->category?->name,
                ],
            ]);
        }

        abort_unless($isSuperAdmin || $isImpersonating || $user->can('view dashboard'), 403);

        $can = [
            'totalUsers'       => $isSuperAdmin || $user->can('dashboard view total users'),
            'recentActivities' => $isSuperAdmin || $user->can('dashboard view recent activities'),
            'unitChart'        => $isSuperAdmin || $user->can('dashboard view unit chart'),
        ];

        // Total users
        $totalUsers = $can['totalUsers'] ? DB::table('users')->count() : null;

        // Units
        $units = DB::table('units')->select('id', 'name', 'unit_code', 'short_form')->get();

        // Stats
        $stats = [
            'totalUsers'     => $can['totalUsers'] ? (int) $totalUsers : null,
            'totalUnits'     => $units->count(),
            'completedTasks' => DB::table('todos')->where('status', 'completed')->count(),
            'pendingTasks'   => DB::table('todos')->where('status', 'pending')->count(),
        ];

        // Unit list for charts
        $unitData = $units->map(fn($u) => [
            'name'      => $u->name ?? 'Unknown',
            'code'      => $u->unit_code ?? '',
            'short_form'=> $u->short_form ?? '',
        ])->values()->all();

        // Recent activities from activity log
        $recentActivities = [];
        if ($can['recentActivities']) {
            $recentActivities = DB::table('activity_log')
                ->join('users', 'activity_log.causer_id', '=', 'users.id')
                ->select(
                    'activity_log.description',
                    'activity_log.created_at',
                    'users.name as user_name'
                )
                ->where('activity_log.causer_type', 'App\Models\User')
                ->orderByDesc('activity_log.created_at')
                ->limit(10)
                ->get()
                ->map(function ($a) {
                    $now = now();
                    $diff = $now->diffInMinutes($a->created_at);
                    if ($diff < 60) {
                        $timeAgo = $diff . ' minutes ago';
                    } elseif ($diff < 1440) {
                        $timeAgo = floor($diff / 60) . ' hours ago';
                    } else {
                        $timeAgo = floor($diff / 1440) . ' days ago';
                    }
                    return [
                        'user'   => $a->user_name,
                        'action' => $a->description,
                        'time'   => $timeAgo,
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Dashboard/Index', [
            'can'              => $can,
            'stats'            => $stats,
            'unitData'         => $unitData,
            'recentActivities' => $recentActivities,
        ]);
    }
}