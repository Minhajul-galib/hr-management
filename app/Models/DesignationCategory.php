<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of designation: "Merchandising", "Machine Operators", "General", ... Each category is its
 * own ladder. Its designations are numbered (serial) from 1 on their own, so serial 1 in one
 * category has nothing to do with serial 1 in another, and the same title can sit in two of them.
 * The user adds, renames and deletes categories on the Designations page.
 *
 * Categories are listed in the order they were added.
 */
class DesignationCategory extends Model
{
    /** Badge colours, handed out by id so a category looks the same wherever it shows. */
    public const COLORS = ['blue', 'purple', 'green', 'orange', 'yellow'];

    protected $fillable = ['name'];

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function color(): string
    {
        return self::COLORS[($this->getKey() - 1) % count(self::COLORS)];
    }
}
