<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class PatToken extends PersonalAccessToken
{
	use HasFactory;
	protected $table = 'personal_access_tokens';

	protected $fillable = [
		'tokenable_type',
		'tokenable_id',
		'user_id',
		'name',
		'token',
		'token_encrypted',
		'abilities',
		'status',
		'last_used_at',
		'expires_at',
		'last_four',
		'revoked_at',
		'revoked_by_user_id',
		'revoke_reason',
		'expiry_notified_stage',
		'expired_notified_at',
	];

	protected $casts = [
		'abilities' => 'json',
		'last_used_at' => 'datetime',
		'expires_at' => 'datetime',
		'revoked_at' => 'datetime',
		'expired_notified_at' => 'datetime',
	];

	protected static function booted(): void
	{
		static::creating(function (PatToken $token): void {
			if ($token->tokenable_type === User::class) {
				$token->user_id = $token->tokenable_id;
			}
		});
	}

	public function tokenable(): MorphTo
	{
		return $this->morphTo();
	}

	public function isServiceAccountKey(): bool
	{
		return $this->tokenable_type === ServiceAccount::class;
	}

	public function user()
	{
		return $this->belongsTo(User::class, 'user_id');
	}

	/**
	 * Generate a custom PAT token with total length 25 (including "atgla-").
	 */
	public static function generateCustomToken(): string
	{
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$token = '';
		$randomLength = 25 - strlen('atgla-');
		
		for ($i = 0; $i < $randomLength; $i++) {
			$token .= $characters[random_int(0, strlen($characters) - 1)];
		}
		
		$fullToken = 'atgla-' . $token;
		
		// Ensure uniqueness
		while (self::where('token', hash('sha256', $fullToken))->exists()) {
			$token = '';
			for ($i = 0; $i < $randomLength; $i++) {
				$token .= $characters[random_int(0, strlen($characters) - 1)];
			}
			$fullToken = 'atgla-' . $token;
		}
		
		return $fullToken;
	}
}
