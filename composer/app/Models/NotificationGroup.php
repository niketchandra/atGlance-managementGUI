<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a workspace (or, with no workspace, the organization) sends
 * notifications: one channel, one target, and the events it subscribes to.
 */
class NotificationGroup extends Model
{
    protected $fillable = [
        'workspace_id',
        'channel',
        'name',
        'target',
        'events',
        'enabled',
        'created_by',
        'last_sent_at',
        'last_status',
        'last_error',
    ];

    protected $casts = [
        'target' => 'encrypted',
        'events' => 'array',
        'enabled' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    protected $hidden = ['target'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * Targets stored as a comma-separated list (emails, phone numbers).
     *
     * @return array<int, string>
     */
    public function targetList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->target))));
    }
}
