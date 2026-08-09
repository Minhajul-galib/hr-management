<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Spatie\Permission\Models\Role;

try {
    $roles = Role::all();
    print_r($roles->toArray());
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
