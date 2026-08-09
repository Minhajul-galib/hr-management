<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleBasedDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Skipping role-based demo user seeding. Only Super System Admin is seeded.');
    }
}
