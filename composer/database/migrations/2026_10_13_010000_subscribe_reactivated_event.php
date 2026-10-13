<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reactivations used to be sent as system.registered. Groups subscribed to that event
     * also get the new system.reactivated, so nobody stops receiving them.
     */
    public function up(): void
    {
        if (!Schema::hasTable('notification_groups')) {
            return;
        }

        foreach (DB::table('notification_groups')->get(['id', 'events']) as $group) {
            $events = json_decode((string) $group->events, true) ?: [];
            if (in_array('system.registered', $events, true) && !in_array('system.reactivated', $events, true)) {
                $events[] = 'system.reactivated';
                DB::table('notification_groups')->where('id', $group->id)->update(['events' => json_encode($events)]);
            }
        }
    }

    public function down(): void
    {
    }
};
