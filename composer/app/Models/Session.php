<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Session extends Model
{
    use HasFactory;

    protected $table = 'session_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a unique session token
     */
    public static function generateToken(): string
    {
        $token = Str::random(60);
        
        // Ensure uniqueness
        while (self::where('token', hash('sha256', $token))->exists()) {
            $token = Str::random(60);
        }

        return $token;
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }
}
