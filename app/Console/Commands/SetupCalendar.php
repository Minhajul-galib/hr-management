<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SetupCalendar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup the Calendar & Todo system with sample data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🗓️  Setting up Calendar & Todo System...');
        
        // Run dashboard setup first (for users and roles)
        $this->info('📊 Setting up dashboard data...');
        Artisan::call('dashboard:setup');
        
        // Seed calendar data
        $this->info('📅 Seeding calendar and todo data...');
        Artisan::call('db:seed', ['--class' => 'CalendarSeeder']);
        
        // Clear cache
        $this->info('🧹 Clearing cache...');
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        
        $this->info('✅ Calendar & Todo system setup completed successfully!');
        $this->info('');
        $this->info('🚀 Access your calendar at: http://127.0.0.1:8000/calendar');
        $this->info('📊 Dashboard available at: http://127.0.0.1:8000/dashboard');
        $this->info('');
        $this->info('📋 Features available:');
        $this->info('   • Interactive FullCalendar with drag & drop');
        $this->info('   • Todo list with priority management');
        $this->info('   • Holiday management (Admin only)');
        $this->info('   • Event reminders and notifications');
        $this->info('   • Mobile responsive design');
        $this->info('');
        $this->info('⏰ To enable reminders, run: php artisan schedule:work');
        
        return 0;
    }
}