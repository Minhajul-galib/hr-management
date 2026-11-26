<?php

namespace Tests;

use App\Models\DesignationCategory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A designation category by name, made when it is not there yet ("General" comes with the migration). */
    protected function designationCategory(string $name = 'General'): DesignationCategory
    {
        return DesignationCategory::firstOrCreate(['name' => $name]);
    }
}
