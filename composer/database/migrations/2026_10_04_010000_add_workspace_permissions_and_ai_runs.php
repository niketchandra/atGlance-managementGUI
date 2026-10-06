<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - workspace_user.permissions: what a workspace admin with the User role may change
     *   (set by a workspace admin with the Admin role). NULL = the defaults in Workspace::PERMISSIONS.
     * - workspace_ai_runs: one row per Vulnerability Checks run, for the progress bar.
     */
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('is_admin');
        });

        Schema::create('workspace_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('trigger', 16);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_runs');

        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
