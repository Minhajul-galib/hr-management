<?php

namespace App\Imports;

use App\Models\Unit;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\Auth;

class UnitsImport implements ToModel, WithHeadingRow, WithValidation
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        return new Unit([
            'unit_code'       => $row['unit_code'] ?? null,
            'short_form'      => $row['short_form'] ?? null,
            'name'            => $row['name'] ?? null,
            'location'        => $row['location'] ?? null,
            'contact_person'  => $row['contact_person'] ?? null,
            'phone_number'    => $row['phone_number'] ?? null,
            'email'           => $row['email'] ?? null,
            'is_active'       => isset($row['is_active']) ? (bool) $row['is_active'] : true,
            'created_by'      => Auth::id(),
        ]);
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'unit_code' => 'required|string|unique:units,unit_code|max:50',
            'short_form' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }
}
