<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readable events for Recent Activity. Rows written before this migration
     * keep event = null and are described from their method and path.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_logs', 'event')) {
                $table->string('event', 64)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('activity_logs', 'description')) {
                $table->string('description', 255)->nullable()->after('event');
            }
            if (!Schema::hasColumn('activity_logs', 'outcome')) {
                $table->string('outcome', 16)->nullable()->after('description');
            }
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $table->index('created_at', 'activity_logs_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_user_created_index');
            $table->dropIndex('activity_logs_created_index');
            $table->dropColumn(['event', 'description', 'outcome']);
        });
    }
};
