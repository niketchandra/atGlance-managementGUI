<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizations';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'description',
        'status',
    ];

    /**
     * Get the users for this organization.
     */
    public function users()
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * Get the system registers for this organization.
     */
    public function systemRegisters()
    {
        return $this->hasMany(SystemRegister::class, 'org_id');
    }

    /**
     * Scope to get only active organizations
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
