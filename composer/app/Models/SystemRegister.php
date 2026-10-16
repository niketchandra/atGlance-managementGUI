<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Notifications\NotificationEvents;
use App\Services\Notifier;
use App\Support\ActivityRecorder;
use App\Support\SecretMask;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

class SystemRegister extends Model
{
    use HasFactory;

    protected $table = 'system_register';
    
    // Disable auto-incrementing since we're using custom IDs
    public $incrementing = false;
    
    // Set the key type to integer for our random ID
    protected $keyType = 'int';

    protected $fillable = [
        'service_account_id',
        'id',
        'pat_token_id',
        'user_id',
        'org_id',
        'workspace_id',
        'system_name',
        'os_type',
        'ip_address',
        'public_ip',
        'public_facing',
        'description',
        'distro',
        'version',
        'is_locked',
        'tags',
        'metadata',
        'status',
        'validation_hash',
    ];
    
    // Derived from validation_hash on save (see booted()); never mass-assigned or serialized.
    protected $hidden = ['system_key_hash'];

    protected $attributes = [
        'status' => 'active',
        'public_facing' => false,
        'is_locked' => false,
    ];

    protected $casts = [
        'public_facing' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /**
     * Notifies the system's workspace when a system is registered, reactivated
     * or deregistered, whichever endpoint made the change.
     */
    protected static function booted(): void
    {
        // Every write of the key (validation_hash) also writes its hash and last four, so
        // all paths (v1 and legacy register, rotate-key) stay in step. validation_hash is
        // still written until Task 5e drops it.
        static::saving(function (SystemRegister $system) {
            if ($system->isDirty('validation_hash') || ($system->system_key_hash === null && !empty($system->validation_hash))) {
                $key = (string) $system->validation_hash;
                $system->system_key_hash = $key === '' ? null : self::keyHash($key);
                $system->system_key_last_four = $key === '' ? null : self::keyLastFour($key);
            }
        });

        static::created(function (SystemRegister $system) {
            $account = $system->service_account_id ? ServiceAccount::query()->find($system->service_account_id) : null;
            ActivityRecorder::record(
                (int) $system->user_id,
                'system.registered',
                'Registered system ' . $system->system_name . ($account ? ' as service account ' . $account->name : ''),
                ActivityRecorder::SUCCESS,
                null,
                $system->workspace_id ? (int) $system->workspace_id : null,
                (int) $system->id,
            );

            if ($system->status === 'active') {
                $system->notifyWorkspace(NotificationEvents::SYSTEM_REGISTERED, 'System registered');
            }
        });

        static::updated(function (SystemRegister $system) {
            if (!$system->wasChanged('status')) {
                return;
            }

            $facts = $system->actorFacts();
            $actorId = \App\Support\ApiIdentity::current()?->user?->id ?? \Illuminate\Support\Facades\Auth::id() ?? (int) $system->user_id;
            $suffix = ' by ' . $facts['Actor'] . ', owner ' . $facts['Original owner'];

            if ($system->status === 'active') {
                ActivityRecorder::record((int) $actorId, 'system.reactivated', 'Reactivated system ' . $system->system_name . $suffix, ActivityRecorder::SUCCESS, null, $system->workspace_id ? (int) $system->workspace_id : null, (int) $system->id);
                $system->notifyWorkspace(NotificationEvents::SYSTEM_REACTIVATED, 'System reactivated', $facts);
            } elseif ($system->getOriginal('status') === 'active') {
                ActivityRecorder::record((int) $actorId, 'system.deregistered', 'Deregistered system ' . $system->system_name . $suffix, ActivityRecorder::SUCCESS, null, $system->workspace_id ? (int) $system->workspace_id : null, (int) $system->id);
                if ($owner = User::find($system->user_id)) {
                    \App\Support\AccountAlerts::systemDeregistered($owner, (string) $system->system_name);
                }
                $system->notifyWorkspace(NotificationEvents::SYSTEM_DEREGISTERED, 'System deregistered', $facts);
            }
        });
    }

    /**
     * Who made the current change and who registered the system, for audit and notices.
     *
     * @return array{Actor: string, Original owner: string}
     */
    public function actorFacts(): array
    {
        $identity = \App\Support\ApiIdentity::current();
        $actor = $identity?->label() ?? (\Illuminate\Support\Facades\Auth::user()?->email ?? 'system');
        $owner = User::query()->find($this->user_id)?->email ?? ('user ' . $this->user_id);

        return ['Actor' => (string) $actor, 'Original owner' => (string) $owner];
    }

    private function notifyWorkspace(string $event, string $action, array $extraFacts = []): void
    {
        $workspaceId = (int) ($this->workspace_id ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        app(Notifier::class)->notify(
            $event,
            $workspaceId,
            $action . ': ' . $this->system_name,
            array_filter([
                'System' => (string) $this->system_name,
                'Workspace' => (string) optional($this->workspace)->name,
                'IP address' => (string) $this->ip_address,
                'OS' => trim($this->os_type . ' ' . $this->distro . ' ' . $this->version),
                'Status' => (string) $this->status,
            ] + $extraFacts, fn (string $value) => $value !== ''),
            ['system_id' => $this->id, 'status' => $this->status],
        );
    }
    
    /**
     * Generate a unique random ID between 5-10 digits
     */
    public static function generateUniqueId(): int
    {
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            // Generate random number between 10000 (5 digits) and 9999999999 (10 digits)
            $randomId = rand(10000, 9999999999);
            
            // Check if ID already exists
            $exists = self::where('id', $randomId)->exists();
            
            $attempts++;
            
            if ($attempts >= $maxAttempts) {
                throw new \RuntimeException('Unable to generate unique system register ID after ' . $maxAttempts . ' attempts');
            }
            
        } while ($exists);
        
        return $randomId;
    }
    
    /**
     * Boot method to auto-generate ID on creation
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = self::generateUniqueId();
            }
        });
    }

    // ------------------------------------------------------------------ system key (hashed)

    /** sha256 hex of a plaintext system key, as stored in system_key_hash. */
    public static function keyHash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Last 4 characters of a key for display; null for keys under 12 characters, which
     * show only the mask (same rule as SecretMask::key).
     */
    public static function keyLastFour(string $key): ?string
    {
        return mb_strlen($key) < 12 ? null : mb_substr($key, -4);
    }

    /**
     * Whether $key is this system's key: compares hashes, or the plaintext column for a
     * row that has no hash yet (written before the backfill).
     */
    public function matchesKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if (!empty($this->system_key_hash)) {
            return hash_equals((string) $this->system_key_hash, self::keyHash($key));
        }

        return !empty($this->validation_hash) && hash_equals((string) $this->validation_hash, $key);
    }

    /** Systems whose key is $key (by hash, or plaintext for rows without a hash). */
    public function scopeWhereSystemKey(Builder $query, string $key): Builder
    {
        self::whereKeyMatches($query->getQuery(), $key, $this->getTable());

        return $query;
    }

    /**
     * Adds "key of the system row aliased $alias is $key" to a query builder.
     */
    public static function whereKeyMatches(QueryBuilder|Builder $query, string $key, string $alias = 'system_register'): void
    {
        $query->where(function ($match) use ($key, $alias) {
            $match->where($alias . '.system_key_hash', self::keyHash($key))
                ->orWhere(function ($legacy) use ($key, $alias) {
                    $legacy->whereNull($alias . '.system_key_hash')->where($alias . '.validation_hash', $key);
                });
        });
    }

    /**
     * The key as the console shows it: mask plus last four, from the hash columns; a row
     * without a hash falls back to masking the plaintext. '' when there is no key.
     */
    public static function maskedKey(?string $keyHash, ?string $lastFour, ?string $plainKey = null): string
    {
        if (!empty($keyHash)) {
            return SecretMask::MASK . (string) $lastFour;
        }

        return SecretMask::key($plainKey);
    }

    /**
     * The key a config file was uploaded with, masked: from the system's last four when it
     * is the system's key, else from the file's own copy (a 1.x host can upload with a key
     * it regenerated locally). '' when the file has no key.
     */
    public static function maskedFileKey(?string $fileKey, ?string $systemKeyHash, ?string $systemLastFour): string
    {
        if ($fileKey === null || $fileKey === '') {
            return '';
        }

        if (!empty($systemKeyHash) && hash_equals((string) $systemKeyHash, self::keyHash($fileKey))) {
            return self::maskedKey($systemKeyHash, $systemLastFour);
        }

        return SecretMask::key($fileKey);
    }

    public function serviceAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class, 'service_account_id');
    }
}
