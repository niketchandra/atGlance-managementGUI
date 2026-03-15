<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawData extends Model
{
    use HasFactory;

    protected $table = 'raw_data';

    protected $fillable = [
        'file_id',
        'user_id',
        'system_register_id',
        'service_id',
        'file_name',
        'service_name',
        'file_data',
        'validation_hash',
        'version',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ConfigurationFile::class, 'file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
