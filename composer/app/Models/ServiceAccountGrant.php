<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a service account may do in one workspace (service_account_workspaces).
 */
class ServiceAccountGrant extends Model
{
    protected $table = 'service_account_workspaces';

    protected $fillable = [
        'service_account_id',
        'workspace_id',
        'permissions',
        'status',
        'granted_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function allows(string $permission): bool
    {
        return $this->status === 'active' && in_array($permission, (array) $this->permissions, true);
    }
}
