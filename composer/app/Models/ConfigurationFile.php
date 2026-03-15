<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConfigurationFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'system_register_id',
        'service_id',
        'file_name',
        'service_name',
        'file_location',
        'validation_hash',
        'version',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rawData(): HasOne
    {
        return $this->hasOne(RawData::class, 'file_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
