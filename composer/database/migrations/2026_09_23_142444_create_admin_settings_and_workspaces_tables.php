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
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_group', 100)->default('general');
            $table->string('setting_key', 150)->unique();
            $table->longText('setting_value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->index(['setting_group', 'setting_key']);
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->string('name', 255);
            $table->string('description', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'status']);
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'is_admin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('admin_settings');
    }
};
