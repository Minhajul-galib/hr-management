# SweetAlert2 Setup Guide

## Installation Complete ✅
SweetAlert2 has been installed and configured in your project.

## Usage

### 1. Import the composable in your Vue component:
```javascript
import { useSweetAlert } from '@/composables/useSweetAlert';

const { showSuccess, showError, showDeleteConfirm } = useSweetAlert();
```

### 2. Available Methods:

#### Success Alert
```javascript
showSuccess('Operation completed successfully!');
```

#### Error Alert
```javascript
showError('Something went wrong!');
```

#### Warning Alert
```javascript
showWarning('Please check your input!');
```

#### Info Alert
```javascript
showInfo('Here is some information');
```

#### Delete Confirmation (Bangla)
```javascript
const deleteItem = async (item) => {
    const result = await showDeleteConfirm(`"${item.name}" আইটেম`);
    
    if (result.isConfirmed) {
        // Perform delete action
        useForm({}).delete(route('items.destroy', item.id));
    }
};
```

#### Custom Confirmation
```javascript
const result = await showConfirm('Do you want to proceed?', 'Confirmation');
if (result.isConfirmed) {
    // User clicked "Yes"
}
```

#### Loading Indicator
```javascript
showLoading('Processing your request...');
// Do async operation
await someAsyncFunction();
closeLoading();
```

## Flash Messages (Auto-configured)
Flash messages from Laravel are automatically shown as SweetAlert:

```php
// In your controller
return back()->with('success', 'Data saved successfully!');
return back()->with('error', 'Failed to save data!');
return back()->with('warning', 'Please review your input!');
return back()->with('info', 'Here is some information');
```

## Example: Replace confirm() with SweetAlert

### Before:
```javascript
const deleteItem = (item) => {
    if (confirm('Are you sure?')) {
        useForm({}).delete(route('items.destroy', item.id));
    }
};
```

### After:
```javascript
import { useSweetAlert } from '@/composables/useSweetAlert';

const { showDeleteConfirm } = useSweetAlert();

const deleteItem = async (item) => {
    const result = await showDeleteConfirm(`"${item.name}" আইটেম`);
    
    if (result.isConfirmed) {
        useForm({}).delete(route('items.destroy', item.id));
    }
};
```

## Files Modified:
1. ✅ `resources/js/composables/useSweetAlert.js` - Created
2. ✅ `resources/js/Layouts/AuthenticatedLayout.vue` - Flash messages configured
3. ✅ Package installed: `sweetalert2`

## Next Steps:
Replace all `confirm()` calls with `showDeleteConfirm()` in your components:
- Units/Index.vue
- Suppliers/Index.vue
- Particulars/Index.vue
- SupplierTypes/Index.vue
- Documents/List.vue
- etc.
