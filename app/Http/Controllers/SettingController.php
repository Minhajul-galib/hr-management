<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToSetVisibility;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        
        return Inertia::render('SystemSettings/Index', [
            'settings' => $settings,
            'currentSettings' => [
                'site_name' => Setting::get('site_name', 'Supplier Management'),
                'site_tagline' => Setting::get('site_tagline', 'Manage your suppliers efficiently'),
                'site_logo' => Setting::get('site_logo'),
                'site_logo_light' => Setting::get('site_logo_light', Setting::get('site_logo')),
                'site_logo_dark' => Setting::get('site_logo_dark', Setting::get('site_logo')),
                'site_favicon' => Setting::get('site_favicon'),
                'site_email' => Setting::get('site_email'),
                'site_phone' => Setting::get('site_phone'),
                'site_address' => Setting::get('site_address'),
            ]
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'site_name' => 'nullable|string|max:255',
            'site_tagline' => 'nullable|string|max:255',
            'site_email' => 'nullable|email|max:255',
            'site_phone' => 'nullable|string|max:50',
            'site_address' => 'nullable|string|max:500',
            // Use file validation instead of image so ICO is accepted reliably.
            // SVG is deliberately excluded: it can carry a <script>, and a
            // logo uploaded here is served straight back from /storage — a
            // direct visit to that URL would run it as a same-origin page.
            'site_logo' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
            'site_logo_light' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
            'site_logo_dark' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
            'site_favicon' => 'nullable|file|mimes:x-icon,png,ico|max:512',
        ]);

        try {
            Log::info('Settings update request', [
                'has_site_logo' => $request->hasFile('site_logo'),
                'has_site_logo_light' => $request->hasFile('site_logo_light'),
                'has_site_logo_dark' => $request->hasFile('site_logo_dark'),
                'has_site_favicon' => $request->hasFile('site_favicon'),
                'all_files' => $request->allFiles(),
                'content_type' => $request->header('Content-Type'),
            ]);
            
            // Handle text fields
            foreach (['site_name', 'site_tagline', 'site_email', 'site_phone', 'site_address'] as $key) {
                if ($request->has($key)) {
                    Setting::set($key, $request->input($key), 'text', 'general');
                }
            }

            // Handle logo upload
            if ($request->hasFile('site_logo')) {
                $this->storeUploadedAsset($request->file('site_logo'), 'site_logo', 'logo');
            }

            if ($request->hasFile('site_logo_light')) {
                $this->storeUploadedAsset($request->file('site_logo_light'), 'site_logo_light', 'logo_light');
            }

            if ($request->hasFile('site_logo_dark')) {
                $this->storeUploadedAsset($request->file('site_logo_dark'), 'site_logo_dark', 'logo_dark');
            }

            // Handle favicon upload
            if ($request->hasFile('site_favicon')) {
                $this->storeUploadedAsset($request->file('site_favicon'), 'site_favicon', 'favicon');
            }

            return back()->with('success', 'Settings updated successfully.');
            
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Settings update failed: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'file_uploads' => [
                    'site_logo' => $request->hasFile('site_logo'),
                    'site_logo_light' => $request->hasFile('site_logo_light'),
                    'site_logo_dark' => $request->hasFile('site_logo_dark'),
                    'site_favicon' => $request->hasFile('site_favicon')
                ],
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return with error message
            return back()->withErrors([
                'upload_error' => 'Failed to update settings: ' . $e->getMessage()
            ])->withInput();
        }
    }

    private function storeUploadedAsset(UploadedFile $file, string $settingKey, string $filenamePrefix): void
    {
        Log::info('Asset upload attempt', [
            'setting_key' => $settingKey,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'is_valid' => $file->isValid(),
            'error' => $file->getError(),
            'error_message' => $file->getErrorMessage(),
        ]);

        $oldAsset = Setting::get($settingKey);
        if ($oldAsset && Storage::disk('public')->exists($oldAsset)) {
            Storage::disk('public')->delete($oldAsset);
        }

        $filename = $filenamePrefix . '_' . time() . '.' . $file->getClientOriginalExtension();
        $assetPath = 'settings/' . $filename;

        try {
            Storage::disk('public')->putFileAs('settings', $file, $filename);
        } catch (UnableToSetVisibility $e) {
            // The file itself was written; only the post-write chmod failed
            // (common on bind-mounted/networked filesystems). Not fatal.
            Log::warning('Asset visibility could not be set, continuing', [
                'setting_key' => $settingKey,
                'error' => $e->getMessage(),
            ]);
            if (!Storage::disk('public')->exists($assetPath)) {
                throw new \Exception('Failed to store file for ' . $settingKey . ': ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('Asset storage failed', [
                'setting_key' => $settingKey,
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
            throw new \Exception('Failed to store file for ' . $settingKey . ': ' . $e->getMessage());
        }

        Log::info('Asset stored successfully', [
            'setting_key' => $settingKey,
            'path' => $assetPath,
        ]);
        Setting::set($settingKey, $assetPath, 'image', 'appearance');
    }
}
