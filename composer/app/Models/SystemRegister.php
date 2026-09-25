<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Notifications\NotificationEvents;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemRegister extends Model
{
    use HasFactory;

    protected $table = 'system_register';
    
    // Disable auto-incrementing since we're using custom IDs
    public $incrementing = false;
    
    // Set the key type to integer for our random ID
    protected $keyType = 'int';

    protected $fillable = [
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
        static::created(function (SystemRegister $system) {
            if ($system->status === 'active') {
                $system->notifyWorkspace(NotificationEvents::SYSTEM_REGISTERED, 'System registered');
            }
        });

        static::updated(function (SystemRegister $system) {
            if (!$system->wasChanged('status')) {
                return;
            }

            if ($system->status === 'active') {
                $system->notifyWorkspace(NotificationEvents::SYSTEM_REGISTERED, 'System reactivated');
            } elseif ($system->getOriginal('status') === 'active') {
                $system->notifyWorkspace(NotificationEvents::SYSTEM_DEREGISTERED, 'System deregistered');
            }
        });
    }

    private function notifyWorkspace(string $event, string $action): void
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
            ], fn (string $value) => $value !== ''),
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
}
