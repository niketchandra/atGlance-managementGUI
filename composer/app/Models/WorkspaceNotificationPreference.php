<?php

namespace App\Models;

use App\Support\WorkspaceSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A member's choice of which workspace events they get by email.
 * Members without a row get the workspace's default list
 * (WorkspaceSettings member_email_default_events).
 */
class WorkspaceNotificationPreference extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'events',
        'email_enabled',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'email_enabled' => 'boolean',
        ];
    }

    /**
     * Lower-cased email addresses of active members of $workspaceId who want $event.
     *
     * @return array<int, string>
     */
    public static function recipientsFor(int $workspaceId, string $event): array
    {
        if (!Schema::hasTable('workspace_notification_preferences')) {
            return [];
        }

        $defaults = (array) WorkspaceSettings::get($workspaceId)['member_email_default_events'];

        $members = DB::table('workspace_user as wu')
            ->join('users as u', 'u.id', '=', 'wu.user_id')
            ->leftJoin('workspace_notification_preferences as p', function ($join) {
                $join->on('p.user_id', '=', 'wu.user_id')->on('p.workspace_id', '=', 'wu.workspace_id');
            })
            ->where('wu.workspace_id', $workspaceId)
            ->where(fn ($query) => $query->whereNull('u.status')->orWhere('u.status', 'active'))
            ->whereNotNull('u.email')
            ->get(['u.email', 'p.id as preference_id', 'p.events', 'p.email_enabled']);

        $recipients = [];
        foreach ($members as $member) {
            if ($member->preference_id === null) {
                $wants = in_array($event, $defaults, true);
            } else {
                $events = json_decode((string) $member->events, true);
                $wants = (bool) $member->email_enabled && in_array($event, is_array($events) ? $events : [], true);
            }

            if ($wants && filter_var($member->email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = strtolower($member->email);
            }
        }

        return array_values(array_unique($recipients));
    }
}
