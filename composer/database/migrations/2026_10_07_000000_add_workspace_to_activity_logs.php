<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties activity log entries to a workspace and system, for the workspace's Recent Activity tab.
 * Past system deregister/reactivate entries are matched to their system by owner and name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_logs', 'workspace_id')) {
                $table->unsignedBigInteger('workspace_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('activity_logs', 'system_register_id')) {
                $table->unsignedBigInteger('system_register_id')->nullable()->after('workspace_id');
            }
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['workspace_id', 'created_at'], 'activity_logs_workspace_created_index');
        });

        $prefixes = ['system.deregistered' => 'Deregistered system ', 'system.reactivated' => 'Reactivated system '];
        DB::table('activity_logs')
            ->whereIn('event', array_keys($prefixes))
            ->whereNull('system_register_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($prefixes) {
                foreach ($rows as $row) {
                    $name = substr((string) $row->description, strlen($prefixes[$row->event]));
                    $system = DB::table('system_register')
                        ->where('user_id', $row->user_id)
                        ->where('system_name', $name)
                        ->orderByDesc('id')
                        ->first(['id', 'workspace_id']);
                    if ($system) {
                        DB::table('activity_logs')->where('id', $row->id)->update([
                            'system_register_id' => $system->id,
                            'workspace_id' => $system->workspace_id,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_workspace_created_index');
            $table->dropColumn(['workspace_id', 'system_register_id']);
        });
    }
};
