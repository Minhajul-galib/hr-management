<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;

try {
    $columns = Schema::getColumnListing('documents');
    print_r($columns);
    if (in_array('type', $columns)) {
        echo "\nTYPE EXISTS\n";
    } else {
        echo "\nTYPE MISSING\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
