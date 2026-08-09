<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Storage;

// Test file upload functionality
echo "Testing file upload functionality...\n";

try {
    // Create a simple test PNG content (1x1 pixel transparent PNG)
    $testContent = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==");
    
    // Test the new upload method
    $filename = 'test_' . time() . '.png';
    $path = 'settings/' . $filename;
    
    Storage::disk('public')->put('settings/' . $filename, $testContent);
    
    if (Storage::disk('public')->exists($path)) {
        echo "✓ File uploaded successfully to: $path\n";
        echo "✓ File size: " . Storage::disk('public')->size($path) . " bytes\n";
        
        // Clean up
        Storage::disk('public')->delete($path);
        echo "✓ Test file cleaned up\n";
        
        echo "\n✅ Upload test PASSED! The fix should work.\n";
    } else {
        echo "❌ File upload failed - file not found after upload\n";
    }
    
} catch (Exception $e) {
    echo "❌ Upload test FAILED: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
