<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForceAttempt extends Model
{
    protected $fillable = [
        'email_hash',
        'user_id',
        'ip',
        'succeeded',
    ];

    protected $casts = [
        'succeeded' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
