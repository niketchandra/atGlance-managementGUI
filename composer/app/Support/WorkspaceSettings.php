<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings a workspace admin sets on the workspace page, stored as one JSON
 * document per workspace in `workspace_settings`:
 *
 * - tags: workspace tags, key/value pairs (e.g. env = prod); systems in the workspace can pick them
 * - ai_*: automatic AI checks of the latest config versions (on upload and on a schedule)
 * - backup_*: scheduled backup of the workspace's stored config files
 * - events / member_email_default_events: which notification events the workspace uses
 *
 * Schedules reuse BackupSettings::FREQUENCIES and its custom cron option.
 */
class WorkspaceSettings
{
    public const DEFAULTS = [
        // [['key' => 'env', 'value' => 'prod'], ...]
        'tags' => [],

        'ai_on_upload' => false,
        'ai_sweep_enabled' => false,
        'ai_sweep_frequency' => 'daily',
        'ai_sweep_cron' => '',
        'ai_sweep_skip_unchanged' => true,
        'ai_last_sweep' => null,

        'backup_enabled' => false,
        'backup_frequency' => 'daily',
        'backup_cron' => '',
        'backup_to_local' => true,
        'backup_to_s3' => false,
        'backup_keep_local' => BackupSettings::DEFAULT_KEEP,
        'backup_keep_s3' => BackupSettings::DEFAULT_KEEP,
        'backup_notes' => '',

        // null = every workspace event; the workspace has not narrowed the list yet.
        'events' => null,
        'member_email_default_events' => [],
    ];

    public const MAX_TAGS = 50;
    public const MAX_TAG_KEY_LENGTH = 40;
    public const MAX_TAG_VALUE_LENGTH = 100;

    public static function get(int $workspaceId): array
    {
        $stored = [];
        if (Schema::hasTable('workspace_settings')) {
            $raw = DB::table('workspace_settings')->where('workspace_id', $workspaceId)->value('settings');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $stored = is_array($decoded) ? $decoded : [];
        }

        // Plain tags and the old system tag list (saved before tags were key/value) become keys without a value.
        $stored['tags'] = self::normalizeTags(array_merge((array) ($stored['tags'] ?? []), (array) ($stored['system_tags'] ?? [])));

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /**
     * Merges $values into the stored settings (only known keys are kept).
     */
    public static function save(int $workspaceId, array $values): array
    {
        $settings = array_replace(self::get($workspaceId), array_intersect_key($values, self::DEFAULTS));
        $now = now();

        DB::table('workspace_settings')->updateOrInsert(
            ['workspace_id' => $workspaceId],
            ['settings' => json_encode($settings), 'updated_at' => $now, 'created_at' => $now]
        );

        return $settings;
    }

    /**
     * Clean key/value tags: trimmed, no empty keys, one entry per key (last wins), at most MAX_TAGS.
     * Accepts [['key' => ..., 'value' => ...]] rows or plain strings ("key" or "key=value").
     *
     * @return array<int, array{key: string, value: string}>
     */
    public static function normalizeTags(array $rows): array
    {
        $tags = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                [$key, $value] = array_pad(explode('=', $row, 2), 2, '');
            } elseif (is_array($row)) {
                [$key, $value] = [(string) ($row['key'] ?? ''), (string) ($row['value'] ?? '')];
            } else {
                continue;
            }

            $key = mb_substr(trim($key), 0, self::MAX_TAG_KEY_LENGTH);
            if ($key === '') {
                continue;
            }

            $tags[mb_strtolower($key)] = ['key' => $key, 'value' => mb_substr(trim($value), 0, self::MAX_TAG_VALUE_LENGTH)];
        }

        return array_slice(array_values($tags), 0, self::MAX_TAGS);
    }

    /**
     * Tags as text: "env=prod", or just "env" when there is no value.
     *
     * @return array<int, string>
     */
    public static function tagLabels(array $tags): array
    {
        return array_map(fn (array $tag) => $tag['value'] === '' ? $tag['key'] : $tag['key'] . '=' . $tag['value'], $tags);
    }

    /**
     * Cron expression for a schedule stored under $prefix ('ai_sweep' or 'backup'), or null.
     */
    public static function expression(array $settings, string $prefix): ?string
    {
        return BackupSettings::expression([
            'frequency' => (string) ($settings[$prefix . '_frequency'] ?? ''),
            'cron_expression' => (string) ($settings[$prefix . '_cron'] ?? ''),
        ]);
    }

    public static function frequencyLabel(array $settings, string $prefix): string
    {
        return BackupSettings::frequencyLabel([
            'frequency' => (string) ($settings[$prefix . '_frequency'] ?? ''),
            'cron_expression' => (string) ($settings[$prefix . '_cron'] ?? ''),
        ]);
    }

    /**
     * Whether the workspace sends $event. Workspaces that never chose send every event.
     */
    public static function sendsEvent(int $workspaceId, string $event): bool
    {
        $events = self::get($workspaceId)['events'];

        return $events === null || in_array($event, (array) $events, true);
    }

    /**
     * Workspaces with a setting turned on, for the scheduler.
     *
     * @return array<int, array> workspace id => settings
     */
    public static function workspacesWith(string $flag): array
    {
        if (!Schema::hasTable('workspace_settings')) {
            return [];
        }

        $result = [];
        $rows = DB::table('workspace_settings as s')
            ->join('workspaces as w', 'w.id', '=', 's.workspace_id')
            ->where('w.status', 'active')
            ->get(['s.workspace_id']);

        foreach ($rows as $row) {
            $settings = self::get((int) $row->workspace_id);
            if (!empty($settings[$flag])) {
                $result[(int) $row->workspace_id] = $settings;
            }
        }

        return $result;
    }
}
