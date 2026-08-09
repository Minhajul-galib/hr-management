<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CalendarEvent;
use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;

class CalendarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        
        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please run DashboardDataSeeder first.');
            return;
        }

        // Create some holidays (visible to all users)
        $holidays = [
            [
                'title' => 'New Year\'s Day',
                'start' => Carbon::create(2026, 1, 1)->startOfDay(),
                'end' => Carbon::create(2026, 1, 1)->endOfDay(),
                'all_day' => true,
                'description' => 'National Holiday - New Year\'s Day'
            ],
            [
                'title' => 'Independence Day',
                'start' => Carbon::create(2026, 3, 26)->startOfDay(),
                'end' => Carbon::create(2026, 3, 26)->endOfDay(),
                'all_day' => true,
                'description' => 'National Holiday - Independence Day of Bangladesh'
            ],
            [
                'title' => 'Victory Day',
                'start' => Carbon::create(2026, 12, 16)->startOfDay(),
                'end' => Carbon::create(2026, 12, 16)->endOfDay(),
                'all_day' => true,
                'description' => 'National Holiday - Victory Day of Bangladesh'
            ],
            [
                'title' => 'Eid ul-Fitr',
                'start' => Carbon::create(2026, 4, 10)->startOfDay(),
                'end' => Carbon::create(2026, 4, 12)->endOfDay(),
                'all_day' => true,
                'description' => 'Religious Holiday - Eid ul-Fitr (3 days)'
            ]
        ];

        foreach ($holidays as $holiday) {
            CalendarEvent::create([
                'user_id' => $users->first()->id, // Created by first user (admin)
                'title' => $holiday['title'],
                'description' => $holiday['description'],
                'start' => $holiday['start'],
                'end' => $holiday['end'],
                'all_day' => $holiday['all_day'],
                'is_holiday' => true,
                'color' => '#dc2626', // Red color for holidays
            ]);
        }

        // Create sample events for each user
        foreach ($users->take(5) as $user) {
            // Regular events
            $events = [
                [
                    'title' => 'Team Meeting',
                    'start' => Carbon::now()->addDays(1)->setTime(10, 0),
                    'end' => Carbon::now()->addDays(1)->setTime(11, 0),
                    'description' => 'Weekly team sync meeting',
                    'color' => '#3788d8'
                ],
                [
                    'title' => 'Project Review',
                    'start' => Carbon::now()->addDays(3)->setTime(14, 0),
                    'end' => Carbon::now()->addDays(3)->setTime(15, 30),
                    'description' => 'Quarterly project review session',
                    'color' => '#059669'
                ],
                [
                    'title' => 'Client Presentation',
                    'start' => Carbon::now()->addDays(5)->setTime(9, 0),
                    'end' => Carbon::now()->addDays(5)->setTime(10, 30),
                    'description' => 'Present project progress to client',
                    'color' => '#dc2626',
                    'reminder_at' => Carbon::now()->addDays(5)->setTime(8, 30)
                ],
                [
                    'title' => 'Training Session',
                    'start' => Carbon::now()->addDays(7)->setTime(13, 0),
                    'end' => Carbon::now()->addDays(7)->setTime(17, 0),
                    'description' => 'Professional development training',
                    'color' => '#7c3aed'
                ],
                [
                    'title' => 'All Day Workshop',
                    'start' => Carbon::now()->addDays(10)->startOfDay(),
                    'end' => Carbon::now()->addDays(10)->endOfDay(),
                    'description' => 'Full day workshop on new technologies',
                    'all_day' => true,
                    'color' => '#f59e0b'
                ]
            ];

            foreach ($events as $eventData) {
                CalendarEvent::create([
                    'user_id' => $user->id,
                    ...$eventData
                ]);
            }

            // Create sample todos
            $todos = [
                [
                    'task' => 'Complete project documentation',
                    'description' => 'Write comprehensive documentation for the new feature',
                    'priority' => 'high',
                    'due_date' => Carbon::now()->addDays(2),
                    'category' => 'Work'
                ],
                [
                    'task' => 'Review code changes',
                    'description' => 'Review pull requests from team members',
                    'priority' => 'medium',
                    'due_date' => Carbon::now()->addDays(1),
                    'category' => 'Work'
                ],
                [
                    'task' => 'Update system dependencies',
                    'description' => 'Update all npm packages to latest versions',
                    'priority' => 'low',
                    'due_date' => Carbon::now()->addWeek(),
                    'category' => 'Maintenance'
                ],
                [
                    'task' => 'Prepare monthly report',
                    'description' => 'Compile monthly performance metrics and analysis',
                    'priority' => 'high',
                    'due_date' => Carbon::now()->addDays(5),
                    'category' => 'Reports',
                    'reminder_at' => Carbon::now()->addDays(4)->setTime(9, 0)
                ],
                [
                    'task' => 'Schedule team building activity',
                    'description' => 'Plan and organize team building event for next month',
                    'priority' => 'medium',
                    'category' => 'HR'
                ],
                [
                    'task' => 'Buy groceries',
                    'description' => 'Weekly grocery shopping',
                    'priority' => 'low',
                    'due_date' => Carbon::now()->addDays(2),
                    'category' => 'Personal'
                ]
            ];

            foreach ($todos as $index => $todoData) {
                Todo::create([
                    'user_id' => $user->id,
                    'order' => $index + 1,
                    ...$todoData
                ]);
            }
        }

        $this->command->info('Calendar and Todo sample data created successfully!');
    }
}