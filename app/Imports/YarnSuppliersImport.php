<?php

namespace App\Imports;

use App\Models\YarnSupplier;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\Auth;

class YarnSuppliersImport implements ToModel, WithHeadingRow, WithValidation
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        return new YarnSupplier([
            'alphabet'    => $row['alphabet'] ?? null,
            'name'        => $row['supplier_name'] ?? null,
            'country'     => $row['origin'] ?? null,
            'excel_input' => $row['excel_input'] ?? null,
            'code'        => $row['supplier_code'] ?? null,
            'contact'     => $row['contact'] ?? null,
            'is_active'   => true,
            'created_by'  => Auth::id(),
        ]);
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'alphabet'      => 'nullable|string|max:5',
            'supplier_name' => 'required|string|max:255',
            'origin'        => 'nullable|string|max:100',
            'excel_input'   => 'nullable|string|max:255',
            'supplier_code' => 'nullable|string|max:50|unique:yarn_suppliers,code',
        ];
    }
}
