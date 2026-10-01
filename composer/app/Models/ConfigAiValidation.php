<?php

namespace App\Models;

use App\Support\UserPreferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved "Validate with AI" result for a configuration file.
 * `result` holds the parsed answer: summary, findings, description, threats.
 */
class ConfigAiValidation extends Model
{
    protected $fillable = [
        'configuration_file_id',
        'user_id',
        'provider',
        'model',
        'status',
        'summary',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The JSON the configuration page renders, for a new or a saved result.
     */
    public function toPayload(): array
    {
        return [
            'success' => true,
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_display' => UserPreferences::datetime($this->created_at),
            'created_by' => $this->user?->name,
        ] + (array) $this->result;
    }
}
