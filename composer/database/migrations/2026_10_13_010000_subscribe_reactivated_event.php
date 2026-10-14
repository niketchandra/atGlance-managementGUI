<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = 'system.registered';
    private const NEW = 'system.reactivated';

    /**
     * Reactivations used to be sent as system.registered. Every event list that has
     * system.registered also gets system.reactivated, so nobody stops receiving them:
     * notification groups, a workspace's event filter and member email defaults, and
     * each member's own choice. Safe to run twice.
     */
    public function up(): void
    {
        if (Schema::hasTable('notification_groups')) {
            foreach (DB::table('notification_groups')->get(['id', 'events']) as $group) {
                $events = $this->withReactivated(json_decode((string) $group->events, true));
                if ($events !== null) {
                    DB::table('notification_groups')->where('id', $group->id)->update(['events' => json_encode($events)]);
                }
            }
        }

        if (Schema::hasTable('workspace_settings')) {
            foreach (DB::table('workspace_settings')->get(['workspace_id', 'settings']) as $row) {
                $settings = json_decode((string) $row->settings, true);
                if (!is_array($settings)) {
                    continue;
                }
                $changed = false;
                foreach (['events', 'member_email_default_events'] as $key) {
                    $events = $this->withReactivated($settings[$key] ?? null);
                    if ($events !== null) {
                        $settings[$key] = $events;
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table('workspace_settings')->where('workspace_id', $row->workspace_id)->update(['settings' => json_encode($settings)]);
                }
            }
        }

        if (Schema::hasTable('workspace_notification_preferences')) {
            foreach (DB::table('workspace_notification_preferences')->get(['id', 'events']) as $row) {
                $events = $this->withReactivated(json_decode((string) $row->events, true));
                if ($events !== null) {
                    DB::table('workspace_notification_preferences')->where('id', $row->id)->update(['events' => json_encode($events)]);
                }
            }
        }
    }

    public function down(): void
    {
    }

    /**
     * The list with system.reactivated added, or null when nothing changes.
     */
    private function withReactivated(mixed $events): ?array
    {
        if (!is_array($events) || !in_array(self::OLD, $events, true) || in_array(self::NEW, $events, true)) {
            return null;
        }

        $events[] = self::NEW;

        return array_values($events);
    }
};
