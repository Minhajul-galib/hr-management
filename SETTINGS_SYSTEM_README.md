# Settings System Documentation

## Overview
Dynamic settings management system for website configuration including logo, favicon, tagline, and general information.

## Features
- **General Settings**: Site name, tagline, email, phone, address
- **Appearance Settings**: Logo and favicon upload with preview
- **Dynamic Fields**: Easy to extend with new settings
- **Caching**: Settings are cached for performance
- **Permission Based**: Only users with 'manage settings' permission can access

## Database Structure

### Settings Table
```sql
- id: Primary key
- key: Unique setting identifier (e.g., 'site_name')
- value: Setting value (text or file path)
- type: Field type (text, textarea, image, file)
- group: Setting group (general, appearance, system)
- timestamps
```

## Usage

### In Controllers
```php
use App\Models\Setting;

// Get a setting
$siteName = Setting::get('site_name', 'Default Name');

// Set a setting
Setting::set('site_name', 'My Company', 'text', 'general');
```

### Using Helper Function
```php
// Get setting value
$siteName = setting('site_name', 'Default Name');

// Get Setting model instance
$setting = setting();
```

### In Blade/Inertia
```php
// Pass to view
return Inertia::render('Page', [
    'siteName' => setting('site_name'),
    'siteLogo' => setting('site_logo'),
]);
```

## File Structure
```
app/
├── Models/Setting.php              # Setting model with caching
├── Http/Controllers/
│   └── SettingController.php       # Settings CRUD controller
└── helpers.php                     # Helper functions

resources/js/Pages/
└── SystemSettings/
    └── Index.vue                   # Settings management page

database/
├── migrations/
│   └── 2026_03_01_071247_create_settings_table.php
└── seeders/
    └── SettingsSeeder.php          # Default settings
```

## Routes
```php
GET  /settings        # View settings page
POST /settings        # Update settings
```

## Permissions
- Permission: `manage settings`
- Assigned to: Super System Admin role

## Adding New Settings

### 1. Add to Seeder (Optional)
```php
// database/seeders/SettingsSeeder.php
['key' => 'new_setting', 'value' => 'default', 'type' => 'text', 'group' => 'general']
```

### 2. Add to Controller
```php
// app/Http/Controllers/SettingController.php
'new_setting' => Setting::get('new_setting', 'default'),
```

### 3. Add to View
```vue
<!-- resources/js/Pages/SystemSettings/Index.vue -->
<TextInput v-model="form.new_setting" />
```

## File Upload Settings
Logo and favicon are stored in `storage/app/public/settings/` directory.

Access via: `/storage/settings/filename.ext`

## Cache Management
Settings are automatically cached and cleared when updated. Manual cache clear:
```bash
php artisan cache:clear
```

## Security
- File uploads are validated (type, size)
- Old files are automatically deleted when replaced
- Permission-based access control
- CSRF protection on all forms

## Example Settings
- `site_name`: Website name
- `site_tagline`: Website tagline/slogan
- `site_logo`: Logo image path
- `site_favicon`: Favicon image path
- `site_email`: Contact email
- `site_phone`: Contact phone
- `site_address`: Office address
