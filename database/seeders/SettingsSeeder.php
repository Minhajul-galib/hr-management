<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaultSettings = [
            ['key' => 'site_name', 'value' => 'HRIS', 'type' => 'text', 'group' => 'general'],
            ['key' => 'site_tagline', 'value' => 'Manage your workforce efficiently', 'type' => 'text', 'group' => 'general'],
            ['key' => 'site_email', 'value' => 'info@example.com', 'type' => 'text', 'group' => 'general'],
            ['key' => 'site_phone', 'value' => '+880 1XXX-XXXXXX', 'type' => 'text', 'group' => 'general'],
            ['key' => 'site_address', 'value' => 'Dhaka, Bangladesh', 'type' => 'textarea', 'group' => 'general'],
        ];

        foreach ($defaultSettings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
