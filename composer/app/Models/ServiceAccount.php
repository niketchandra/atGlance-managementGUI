<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A non-human identity. Workspace scope: one workspace, fixed at creation. Organization
 * scope: only the workspaces it has an active grant for. Keys are PatToken rows with
 * tokenable_type = ServiceAccount and the atgsa- prefix.
 */
class ServiceAccount extends Model
{
    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_WORKSPACE = 'workspace';

    public const PERMISSIONS = [
        'systems.register' => 'Register new servers in this workspace',
        'configs.write' => 'Upload configuration files',
        'configs.read' => 'List and download configuration files',
        'systems.rotate_key' => "Rotate its own server's system key",
    ];

    public const DEFAULT_PERMISSIONS = ['systems.register', 'configs.write', 'configs.read'];

    /** Fixed at creation; see booted(). */
    private const IMMUTABLE = ['scope', 'org_id', 'workspace_id'];

    protected $fillable = [
        'name',
        'description',
        'team',
        'scope',
        'org_id',
        'workspace_id',
        'owner_user_id',
        'created_by_user_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (ServiceAccount $account): void {
            foreach (self::IMMUTABLE as $column) {
                if ($account->isDirty($column)) {
                    $account->{$column} = $account->getOriginal($column);
                }
            }
        });
    }

    /**
     * Valid permission keys from user input, in PERMISSIONS order, without duplicates.
     *
     * @return list<string>
     */
    public static function normalizePermissions(mixed $input): array
    {
        $input = is_array($input) ? array_map('strval', $input) : [];

        return array_values(array_filter(array_keys(self::PERMISSIONS), fn (string $key) => in_array($key, $input, true)));
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ServiceAccountGrant::class);
    }

    public function keys(): MorphMany
    {
        return $this->morphMany(PatToken::class, 'tokenable');
    }

    public function activeKeys(): MorphMany
    {
        return $this->keys()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOrganizationScope(): bool
    {
        return $this->scope === self::SCOPE_ORGANIZATION;
    }

    public function activeGrantFor(int $workspaceId): ?ServiceAccountGrant
    {
        if ($workspaceId <= 0) {
            return null;
        }

        return $this->grants()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Read on every call (no cache), so a revoked grant or a disabled account stops the next request.
     */
    public function can(string $permission, int $workspaceId): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return (bool) $this->activeGrantFor($workspaceId)?->allows($permission);
    }
}
