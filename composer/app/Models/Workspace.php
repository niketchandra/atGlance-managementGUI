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
            ->withPivot('is_admin', 'permissions')
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
     * Whether $user may change this workspace's members and settings: the super
     * admin of its organization, or a workspace admin (whatever the account role).
     */
    public function canBeManagedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ((int) ($user->rbac_id ?? 0) === 100) {
            return (int) ($this->org_id ?? 200) === (int) ($user->org_id ?? 200);
        }

        return $this->hasUserAsAdmin($user->id);
    }

    /**
     * What a workspace admin may change. Admin-role workspace admins and the super admin
     * may change everything. For a workspace admin with the User role, an Admin-role
     * workspace admin picks these when adding them or later (Members tab); the values
     * here are the defaults until then.
     */
    public const PERMISSIONS = [
        'general' => ['label' => 'Change General settings (name, description)', 'default' => true],
        'members' => ['label' => 'Add and remove users', 'default' => true],
        'admins' => ['label' => 'Add and remove other workspace admins', 'default' => false],
        'vulnerability_checks' => ['label' => 'Change Vulnerability Checks', 'default' => false],
        'backups' => ['label' => 'Change Backups', 'default' => false],
        'notifications' => ['label' => 'Change Notifications', 'default' => false],
    ];

    /**
     * Permission => bool for $user in this workspace. Everything false for non-admins.
     *
     * @return array<string, bool>
     */
    public function permissionsFor(?User $user): array
    {
        $none = array_map(fn () => false, self::PERMISSIONS);

        if (!$this->canBeManagedBy($user)) {
            return $none;
        }

        if (in_array((int) ($user->rbac_id ?? 0), [100, 101], true)) {
            return array_map(fn () => true, self::PERMISSIONS);
        }

        $stored = $this->users()->where('users.id', $user->id)->first()?->pivot?->permissions;

        return self::resolvePermissions(is_string($stored) ? json_decode($stored, true) : $stored);
    }

    public function allows(?User $user, string $permission): bool
    {
        return $this->permissionsFor($user)[$permission] ?? false;
    }

    /**
     * Stored choices merged over the defaults; unknown keys are dropped.
     *
     * @return array<string, bool>
     */
    public static function resolvePermissions(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $resolved = [];
        foreach (self::PERMISSIONS as $key => $meta) {
            $resolved[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : $meta['default'];
        }

        return $resolved;
    }

    /**
     * Saves a workspace admin's permissions (only meaningful for User-role admins).
     *
     * @param array<string, bool> $permissions
     */
    public function setPermissions(int $userId, array $permissions): void
    {
        $this->users()->updateExistingPivot($userId, [
            'permissions' => json_encode(self::resolvePermissions($permissions)),
        ]);
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
