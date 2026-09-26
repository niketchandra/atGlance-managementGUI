<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A user's activity in plain language, for Recent Activity on /profile,
 * /profile/activity and the admin user-profile page.
 *
 * Only the user's own rows are read, and request payloads are never shown.
 */
class ActivityFeed
{
    public const TYPES = [
        'signin' => 'Sign-ins',
        'security' => 'Account & security',
        'apikeys' => 'API keys',
        'systems' => 'Systems & backups',
    ];

    /**
     * event => [type, Font Awesome icon]
     */
    public const EVENTS = [
        'auth.login' => ['signin', 'fa-sign-in-alt'],
        'auth.login_failed' => ['signin', 'fa-exclamation-triangle'],
        'auth.login_blocked' => ['signin', 'fa-ban'],
        'auth.logout' => ['signin', 'fa-sign-out-alt'],
        'account.registered' => ['security', 'fa-user-plus'],
        'account.created_by_admin' => ['security', 'fa-user-plus'],
        'account.changed_by_admin' => ['security', 'fa-user-cog'],
        'profile.updated' => ['security', 'fa-user-edit'],
        'profile.setup' => ['security', 'fa-user-check'],
        'preferences.updated' => ['security', 'fa-sliders-h'],
        'password.changed' => ['security', 'fa-lock'],
        'pin.reset' => ['security', 'fa-key'],
        'session.ended' => ['signin', 'fa-laptop'],
        'session.ended_others' => ['signin', 'fa-laptop'],
        'apikey.created' => ['apikeys', 'fa-plus-circle'],
        'apikey.viewed' => ['apikeys', 'fa-eye'],
        'apikey.revoked' => ['apikeys', 'fa-times-circle'],
        'system.registered' => ['systems', 'fa-server'],
        'system.reactivated' => ['systems', 'fa-server'],
        'system.deregistered' => ['systems', 'fa-power-off'],
        'config.uploaded' => ['systems', 'fa-file-code'],
    ];

    /**
     * Rows written before readable events existed: "METHOD path" => [text, type, icon].
     * Their outcome is unknown, so none is shown.
     */
    private const LEGACY = [
        'POST /login' => ['Sign-in attempt', 'signin', 'fa-sign-in-alt'],
        'POST /logout' => ['Signed out', 'signin', 'fa-sign-out-alt'],
        'POST /register' => ['Created account', 'security', 'fa-user-plus'],
        'POST /settings/update' => ['Updated profile', 'security', 'fa-user-edit'],
        'POST /password/update' => ['Changed password', 'security', 'fa-lock'],
        'POST /settings/api-keys' => ['Created an API key', 'apikeys', 'fa-plus-circle'],
        'POST /settings/api-keys/view' => ['Viewed an API key', 'apikeys', 'fa-eye'],
        'POST /settings/api-keys/revoke' => ['Revoked an API key', 'apikeys', 'fa-times-circle'],
        'POST /api/system-register' => ['Registered a system', 'systems', 'fa-server'],
        'POST /api/system-deregister' => ['Deregistered a system', 'systems', 'fa-power-off'],
        'POST /api/config-files/upload' => ['Backed up a configuration file', 'systems', 'fa-file-code'],
        'POST /api/auth/login' => ['Signed in from the CLI', 'signin', 'fa-terminal'],
    ];

    /** Uploads to one system within this many minutes are shown as one entry. */
    private const GROUP_MINUTES = 10;

    /**
     * @return Collection<int, array>
     */
    public static function latest(int $userId, int $limit = 10): Collection
    {
        // Read extra rows so grouped uploads still leave $limit entries.
        $rows = self::query($userId)->limit($limit * 5)->get();

        return self::present($rows)->take($limit)->values();
    }

    public static function paginate(int $userId, ?string $type, int $perPage = 25): LengthAwarePaginator
    {
        $query = self::query($userId);
        if ($type !== null && isset(self::TYPES[$type])) {
            self::filterByType($query, $type);
        }

        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(self::present($page->getCollection()));

        return $page;
    }

    private static function query(int $userId): Builder
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->where(fn (Builder $query) => $query
                ->whereIn('event', array_keys(self::EVENTS))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('event')->where(function (Builder $paths) {
                    foreach (array_keys(self::LEGACY) as $key) {
                        [$method, $path] = explode(' ', $key, 2);
                        $paths->orWhere(fn (Builder $match) => $match->where('method', $method)->where('path', $path));
                    }
                })))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private static function filterByType(Builder $query, string $type): void
    {
        $events = array_keys(array_filter(self::EVENTS, fn (array $meta) => $meta[0] === $type));
        $legacy = array_keys(array_filter(self::LEGACY, fn (array $meta) => $meta[1] === $type));

        $query->where(fn (Builder $scoped) => $scoped
            ->whereIn('event', $events)
            ->orWhere(fn (Builder $old) => $old->whereNull('event')->where(function (Builder $paths) use ($legacy) {
                $paths->whereRaw('1 = 0');
                foreach ($legacy as $key) {
                    [$method, $path] = explode(' ', $key, 2);
                    $paths->orWhere(fn (Builder $match) => $match->where('method', $method)->where('path', $path));
                }
            })));
    }

    /**
     * @param Collection<int, ActivityLog> $rows newest first
     */
    private static function present(Collection $rows): Collection
    {
        $items = collect();

        foreach ($rows as $row) {
            $item = self::item($row);
            $previous = $items->last();

            if ($previous !== null && self::groupsWith($previous, $item)) {
                $previous['count']++;
                $previous['text'] = 'Backed up ' . $previous['count'] . ' configuration files on ' . $previous['system'];
                $items->put($items->keys()->last(), $previous);

                continue;
            }

            $items->push($item);
        }

        return $items->values();
    }

    private static function item(ActivityLog $row): array
    {
        if ($row->event !== null) {
            [$type, $icon] = self::EVENTS[$row->event] ?? ['security', 'fa-circle'];
            $text = (string) $row->description;
            $outcome = $row->outcome;
        } else {
            [$text, $type, $icon] = self::LEGACY[$row->method . ' ' . $row->path] ?? [$row->method . ' ' . $row->path, 'security', 'fa-circle'];
            $outcome = null;
        }

        preg_match('/^Backed up .+ on (.+)$/', $text, $upload);

        return [
            'event' => $row->event,
            'type' => $type,
            'icon' => $icon,
            'text' => $text,
            'outcome' => $outcome,
            'at' => Carbon::parse($row->created_at),
            'device' => UserAgent::describe($row->user_agent),
            'ip' => $row->ip_address,
            'system' => $row->event === 'config.uploaded' ? ($upload[1] ?? null) : null,
            'count' => 1,
        ];
    }

    private static function groupsWith(array $previous, array $item): bool
    {
        return $previous['event'] === 'config.uploaded'
            && $item['event'] === 'config.uploaded'
            && $previous['system'] !== null
            && $previous['system'] === $item['system']
            && $previous['at']->diffInMinutes($item['at'], true) <= self::GROUP_MINUTES;
    }
}
