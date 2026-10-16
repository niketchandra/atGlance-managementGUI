<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = [
        'org_id',
        'type',
        'user_id',
        'email_hash',
        'ip',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Event type constants.
     */
    public const TYPE_FORCE_FAILED = 'force.failed';
    public const TYPE_FORCE_LOCKED = 'force.locked';
    public const TYPE_FORCE_UNLOCKED = 'force.unlocked';
    public const TYPE_FORCE_SUCCEEDED = 'force.succeeded';
    public const TYPE_KEY_REVOKED = 'key.revoked';
    public const TYPE_KEY_EXPIRED_USED = 'key.expired_used';
    public const TYPE_SYSTEM_KEY_ROTATED = 'system.key_rotated';
    public const TYPE_SYSTEM_REPLACED = 'system.replaced';
    public const TYPE_SYSTEM_SERVICE_ACCOUNT_CHANGED = 'system.service_account_changed';
    public const TYPE_LEGACY_API_USED = 'legacy_api.used';
}
