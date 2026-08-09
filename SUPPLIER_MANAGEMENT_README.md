# Supplier Management System

## Overview
Complete supplier management system with CRUD operations and Excel import functionality.

## Features
- ✅ List all suppliers with filtering
- ✅ View supplier details
- ✅ Create new suppliers
- ✅ Edit existing suppliers
- ✅ Delete suppliers
- ✅ Import suppliers from Excel file
- ✅ Category management
- ✅ Particulars (many-to-many relationship)
- ✅ Status tracking (active/inactive)
- ✅ Verification status
- ✅ Financial tracking (opening balance, total owing)

## Routes
All routes are protected by authentication middleware.

```php
Route::resource('suppliers', SupplierController::class);
Route::post('/suppliers/import', [SupplierController::class, 'import'])->name('suppliers.import');
```

Available routes:
- `GET /suppliers` - List all suppliers
- `GET /suppliers/create` - Show create form
- `POST /suppliers` - Store new supplier
- `GET /suppliers/{id}` - View supplier details
- `GET /suppliers/{id}/edit` - Show edit form
- `PUT /suppliers/{id}` - Update supplier
- `DELETE /suppliers/{id}` - Delete supplier
- `POST /suppliers/import` - Import from Excel

## Database Structure

### Suppliers Table
- `id` - Primary key
- `code` - Unique supplier code (auto-generated: SUP00001)
- `name` - Supplier name
- `category_id` - Foreign key to supplier_types
- `contact_person` - Contact person name
- `email` - Email address
- `phone_number` - Phone number
- `address` - Full address
- `bin_number` - Business Identification Number
- `opening_balance` - Initial balance
- `total_owing` - Current owing amount
- `avg_rating` - Average rating
- `total_evaluations` - Number of evaluations
- `is_verified` - Verification status (boolean)
- `status` - active/inactive

### Relationships
- **Category**: BelongsTo SupplierCategory (supplier_types table)
- **Particulars**: BelongsToMany Particular (through supplier_particular pivot table)

## Excel Import Format

### Required Columns
- `name` - Supplier name (required)

### Optional Columns
- `code` - Supplier code (auto-generated if not provided)
- `category` - Category name (matches supplier_types.supplier_types)
- `category_id` - Category ID
- `contact_person` - Contact person name
- `email` - Email address
- `phone_number` - Phone number
- `address` - Full address
- `bin_number` - BIN number
- `opening_balance` - Opening balance (numeric)
- `is_verified` - 1 for verified, 0 for not verified
- `status` - active or inactive (default: active)

### Sample Excel Format
```
| code    | name           | category | contact_person | email              | phone_number | address      | bin_number | opening_balance | is_verified | status |
|---------|----------------|----------|----------------|--------------------| -------------|--------------|------------|-----------------|-------------|--------|
| SUP0001 | ABC Suppliers  | Type A   | John Doe       | john@abc.com       | 01712345678  | Dhaka        | 123456789  | 10000.00        | 1           | active |
| SUP0002 | XYZ Company    | Type B   | Jane Smith     | jane@xyz.com       | 01798765432  | Chittagong   | 987654321  | 5000.00         | 0           | active |
```

### Supported File Formats
- .xlsx (Excel 2007+)
- .xls (Excel 97-2003)
- .csv (Comma-separated values)

## Usage

### Accessing the System
Navigate to `/suppliers` in your browser after logging in.

### Creating a Supplier
1. Click "Create New Supplier" button
2. Fill in the required fields (Code, Name, Category, Status)
3. Optionally add contact information, financial details, and particulars
4. Click "Create Supplier"

### Importing from Excel
1. Click "Import Excel" button
2. Select your Excel file (.xlsx, .xls, or .csv)
3. Click "Import"
4. System will validate and import the data

### Editing a Supplier
1. Click "Edit" button on any supplier row
2. Update the information
3. Click "Update Supplier"

### Viewing Details
Click "View" button on any supplier row to see complete details.

### Deleting a Supplier
Click "Delete" button and confirm the action.

## Components Used
- `AuthenticatedLayout` - Main layout wrapper
- `PrimaryButton` - Primary action buttons
- `SecondaryButton` - Secondary action buttons
- `TextInput` - Text input fields
- `InputLabel` - Form labels
- `InputError` - Validation error messages
- `Modal` - Modal dialogs

## Validation Rules
- Code: Required, unique, max 50 characters
- Name: Required, max 255 characters
- Category: Required, must exist in supplier_types
- Email: Valid email format
- Opening Balance: Numeric, minimum 0
- Status: Must be 'active' or 'inactive'

## Notes
- Supplier codes are auto-generated with format: SUP00001, SUP00002, etc.
- The system supports Bengali currency symbol (৳) for financial displays
- All timestamps are automatically managed by Laravel
- Soft deletes are not implemented (permanent deletion)
