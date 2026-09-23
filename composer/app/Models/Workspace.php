<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Workspace extends Model
{
    use HasFactory;

    protected $table = 'workspaces';

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'status',
    ];

    /**
     * Get the users for this workspace.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('is_admin')
            ->withTimestamps();
    }

    /**
     * Get the admins for this workspace.
     */
    public function admins()
    {
        return $this->users()->wherePivot('is_admin', true);
    }

    /**
     * Get the regular users for this workspace.
     */
    public function regularUsers()
    {
        return $this->users()->wherePivot('is_admin', false);
    }

    /**
     * Get the organization for this workspace.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Scope to get only active workspaces
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if a user is an admin of this workspace.
     */
    public function hasUserAsAdmin($userId): bool
    {
        return $this->users()
            ->where('user_id', $userId)
            ->wherePivot('is_admin', true)
            ->exists();
    }

    /**
     * Add a user to this workspace as admin or regular user.
     */
    public function addUser($userId, $isAdmin = false): void
    {
        $this->users()->syncWithoutDetaching([
            $userId => ['is_admin' => $isAdmin],
        ]);
    }

    /**
     * Remove a user from this workspace.
     */
    public function removeUser($userId): void
    {
        $this->users()->detach($userId);
    }
}
