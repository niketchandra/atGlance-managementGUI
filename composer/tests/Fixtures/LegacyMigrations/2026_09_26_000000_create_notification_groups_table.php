<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('notification_groups')) {
            Schema::create('notification_groups', function (Blueprint $table) {
                $table->id();
                // Null means an organization-level group (org-wide events, super admin only).
                $table->unsignedBigInteger('workspace_id')->nullable();
                $table->string('channel', 30);
                $table->string('name', 255);
                // Encrypted: webhook URLs, chat IDs, emails and phone numbers.
                $table->text('target');
                $table->json('events');
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->string('last_status', 20)->nullable();
                $table->string('last_error', 1000)->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->index(['workspace_id', 'enabled']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_groups');
    }
};
