<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class PatToken extends PersonalAccessToken
{
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
