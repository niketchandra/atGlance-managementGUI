<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Reset queue" on the Vulnerability Checks tab: a cancelled run's queued
     * jobs end without calling the AI.
     */
    public function up(): void
    {
        Schema::table('workspace_ai_runs', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_ai_runs', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
