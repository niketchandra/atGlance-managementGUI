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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('is_admin')
            ->withTimestamps();
    }

    public function admins()
    {
        return $this->users()->wherePivot('is_admin', true);
    }

    public function regularUsers()
    {
        return $this->users()->wherePivot('is_admin', false);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function hasUserAsAdmin($userId): bool
    {
        return $this->users()
            ->where('user_id', $userId)
            ->wherePivot('is_admin', true)
            ->exists();
    }

    public function addUser($userId, $isAdmin = false): void
    {
        $this->users()->syncWithoutDetaching([
            $userId => ['is_admin' => $isAdmin],
        ]);
    }

    public function removeUser($userId): void
    {
        $this->users()->detach($userId);
    }
}
