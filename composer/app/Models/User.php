<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rbac_id',
        'org_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'dob',
        'phone',
        'password',
        'password_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Override getAuthPassword to use password_hash for API and password for web
     */
    public function getAuthPassword()
    {
        // Return the hashed password from password_hash field (it's always populated)
        return $this->password_hash;
    }

    /**
     * Set the password attribute
     */
    public function setPasswordAttribute($value)
    {
        // If the value is already hashed (starts with $2y$), use it as is
        // Otherwise hash it
        $hashedValue = (strpos($value, '$2y$') === 0) ? $value : Hash::make($value);
        
        $this->attributes['password'] = $hashedValue;
        $this->attributes['password_hash'] = $hashedValue; // Keep both in sync
    }


    public function configurationFiles(): HasMany
    {
        return $this->hasMany(ConfigurationFile::class);
    }

    public function rawData(): HasMany
    {
        return $this->hasMany(RawData::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function patTokens(): HasMany
    {
        return $this->hasMany(PatToken::class, 'user_id');
    }

    /**
     * Get the RBAC role for this user.
     */
    public function rbac()
    {
        return $this->belongsTo(Rbac::class, 'rbac_id');
    }

    /**
     * Get the organization for this user.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->rbac?->hasPermission($permission) ?? false;
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->rbac_id === 100;
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return in_array($this->rbac_id, [100, 101]);
    }
}
