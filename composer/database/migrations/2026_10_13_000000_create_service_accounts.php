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
     *
     * Every step checks what already exists: MySQL DDL is not transactional, so a run
     * that stops halfway must be able to finish on the next start.
     */
    public function up(): void
    {
        if (!Schema::hasTable('service_accounts')) {
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
        }

        if (!Schema::hasTable('service_account_workspaces')) {
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
            });
        }

        // Named explicitly: the generated name is 65 characters, over MySQL's 64.
        if (!Schema::hasIndex('service_account_workspaces', 'sa_workspaces_account_workspace_unique')) {
            Schema::table('service_account_workspaces', function (Blueprint $table) {
                $table->unique(['service_account_id', 'workspace_id'], 'sa_workspaces_account_workspace_unique');
            });
        }

        $tokenColumns = [
            'last_four' => fn (Blueprint $table) => $table->string('last_four', 4)->nullable()->after('token'),
            'revoked_at' => fn (Blueprint $table) => $table->timestamp('revoked_at')->nullable(),
            'revoked_by_user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('revoked_by_user_id')->nullable(),
            'revoke_reason' => fn (Blueprint $table) => $table->string('revoke_reason', 50)->nullable(),
            'expiry_notified_stage' => fn (Blueprint $table) => $table->unsignedSmallInteger('expiry_notified_stage')->nullable(),
            'expired_notified_at' => fn (Blueprint $table) => $table->timestamp('expired_notified_at')->nullable(),
        ];
        foreach ($tokenColumns as $column => $define) {
            if (!Schema::hasColumn('personal_access_tokens', $column)) {
                Schema::table('personal_access_tokens', $define);
            }
        }

        foreach (['system_register', 'configuration_files', 'raw_data', 'services'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'service_account_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id');
                });
            }
            if (!Schema::hasIndex($tableName, $tableName . '_service_account_id_index')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index('service_account_id');
                });
            }
            // system_register.user_id stays required: it is the person who registered.
            if ($tableName !== 'system_register') {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('user_id')->nullable()->change();
                });
            }
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
