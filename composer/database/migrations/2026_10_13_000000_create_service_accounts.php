<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service accounts (spec 2026-10-13): non-human identities scoped to one workspace
     * or to granted workspaces of an organization. Keys stay in personal_access_tokens.
     * Data a service account uploads has service_account_id set and user_id null.
     */
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 512)->nullable();
            $table->string('team', 100)->nullable();
            $table->string('scope', 20);
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'scope', 'status']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('service_account_workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_account_id');
            $table->unsignedBigInteger('workspace_id');
            $table->json('permissions');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('service_account_id')->references('id')->on('service_accounts')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['service_account_id', 'workspace_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('last_four', 4)->nullable()->after('token');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoke_reason', 50)->nullable();
            $table->unsignedSmallInteger('expiry_notified_stage')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
        });

        Schema::table('system_register', function (Blueprint $table) {
            $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id');
            $table->index('service_account_id');
        });

        foreach (['configuration_files', 'raw_data', 'services'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id');
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->index('service_account_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['configuration_files', 'raw_data', 'services', 'system_register'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['service_account_id']);
                $table->dropColumn('service_account_id');
            });
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['last_four', 'revoked_at', 'revoked_by_user_id', 'revoke_reason', 'expiry_notified_stage', 'expired_notified_at']);
        });

        Schema::dropIfExists('service_account_workspaces');
        Schema::dropIfExists('service_accounts');
    }
};
