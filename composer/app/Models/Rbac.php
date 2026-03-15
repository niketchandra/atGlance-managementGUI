<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rbac extends Model
{
    use HasFactory;

    protected $table = 'rbac';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'role_name',
        'read',
        'write',
        'execute',
    ];

    protected $casts = [
        'read' => 'boolean',
        'write' => 'boolean',
        'execute' => 'boolean',
    ];

    /**
     * Get the users for this role.
     */
    public function users()
    {
        return $this->hasMany(User::class, 'rbac_id');
    }

    /**
     * Check if role has permission
     */
    public function hasPermission(string $permission): bool
    {
        return $this->{$permission} ?? false;
    }
}
