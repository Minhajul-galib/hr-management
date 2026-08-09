<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class Department extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the users in this department.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function merchantTeams(): HasMany
    {
        return $this->hasMany(MerchantTeam::class, 'department_id');
    }

    /**
     * Get active departments only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the count of users in this department.
     */
    public function getUsersCountAttribute(): int
    {
        return $this->users()->count();
    }
}
