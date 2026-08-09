<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\DocumentStatus;

try {
    $statuses = DocumentStatus::all();
    print_r($statuses->toArray());
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
