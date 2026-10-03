<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-workspace settings (tags, automatic AI checks, workspace backups,
     * notification choices), the history of workspace backups, and each
     * member's notification preferences for a workspace.
     */
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->primary();
            $table->json('settings');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('workspace_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('status', 16);
            $table->text('message')->nullable();
            $table->string('object_key', 512)->nullable();
            $table->string('disk', 16)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'started_at']);
        });

        Schema::create('workspace_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->json('events');
            $table->boolean('email_enabled')->default(true);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
        });

        // Validations run by the queue (on upload or on a schedule) have no user.
        Schema::table('config_ai_validations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('trigger', 16)->default('manual')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('config_ai_validations', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });

        Schema::dropIfExists('workspace_notification_preferences');
        Schema::dropIfExists('workspace_backup_runs');
        Schema::dropIfExists('workspace_settings');
    }
};
