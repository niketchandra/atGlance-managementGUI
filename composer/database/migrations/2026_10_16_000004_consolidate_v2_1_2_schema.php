<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AtGlance v2.1.2 consolidated schema. Combines all 6 migrations into one complete
 * schema suitable for fresh installations. Existing databases continue using individual
 * migration records in the migrations table; this allows new deployments to apply 2.1.2
 * in a single migration run.
 *
 * Fresh install: runs once, creates complete 2.1.2 schema.
 * Upgrade: skips (old migrations already applied).
 * Re-run after crash: safe, checks for existing tables/columns.
 *
 * Merged from:
 *   - 2026_10_14_000000_version_2_1_2_schema.php (base)
 *   - 2026_10_15_171352_fix_token_expiry_default.php
 *   - 2026_10_16_000000_add_force_lockout_support.php
 *   - 2026_10_16_000001_create_security_events_table.php
 *   - 2026_10_16_000002_add_system_key_hash.php
 *   - 2026_10_16_000003_add_config_content_sha256.php
 */
return new class extends Migration
{
    private array $created = [];
    private bool $backfillPasswordDates = false;

    public function up(): void
    {
        $this->identityAndAccess();
        $this->framework();
        $this->settingsAndAudit();
        $this->workspaces();
        $this->systemsAndConfigs();
        $this->serviceAccounts();
        $this->temporaryMessages();
        $this->forceAttempts();
        $this->securityEvents();
        $this->systemKeyHash();
        $this->configContentSha256();
        $this->startingRowsAndData();
    }

    public function down(): void
    {
        // The schema baseline cannot be rolled back table by table; restore a backup instead.
    }

    // ================================================================== 1. identity and access

    private function identityAndAccess(): void
    {
        $this->createTable('rbac', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('role_name', 50)->unique();
            $table->boolean('read')->default(false);
            $table->boolean('write')->default(false);
            $table->boolean('execute')->default(false);
            $table->timestamps();
        });

        $this->createTable('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 255);
            $table->string('description', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        $this->createTable('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rbac_id')->default(102);
            $table->unsignedBigInteger('org_id')->default(200);
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->date('dob')->nullable();
            $table->string('pin')->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash');
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->string('status', 20)->default('active');
            $table->json('preferences')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('previous_login_at')->nullable();
            $table->string('previous_login_ip', 45)->nullable();
            $table->timestamps();

            $table->foreign('rbac_id')->references('id')->on('rbac')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // Upgrades: columns added to users after it first shipped.
        $this->addColumn('users', 'rbac_id', function (Blueprint $table) {
            $table->unsignedBigInteger('rbac_id')->default(102)->after('id');
            $table->foreign('rbac_id')->references('id')->on('rbac')->cascadeOnDelete();
        });
        $this->addColumn('users', 'org_id', function (Blueprint $table) {
            $table->unsignedBigInteger('org_id')->default(200)->after('rbac_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        $this->addColumn('users', 'first_name', fn (Blueprint $table) => $table->string('first_name')->nullable()->after('name'));
        $this->addColumn('users', 'last_name', fn (Blueprint $table) => $table->string('last_name')->nullable()->after('first_name'));
        $this->addColumn('users', 'phone', fn (Blueprint $table) => $table->string('phone', 20)->nullable()->after('dob'));
        $this->addColumn('users', 'pin', fn (Blueprint $table) => $table->string('pin')->nullable()->after('phone'));
        $this->addColumn('users', 'password', fn (Blueprint $table) => $table->string('password')->nullable()->after('password_hash'));
        $this->addColumn('users', 'preferences', fn (Blueprint $table) => $table->json('preferences')->nullable()->after('status'));
        $this->addColumn('users', 'last_login_at', fn (Blueprint $table) => $table->timestamp('last_login_at')->nullable()->after('status'));
        $this->addColumn('users', 'last_login_ip', fn (Blueprint $table) => $table->string('last_login_ip', 45)->nullable()->after('last_login_at'));
        $this->addColumn('users', 'previous_login_at', fn (Blueprint $table) => $table->timestamp('previous_login_at')->nullable()->after('last_login_ip'));
        $this->addColumn('users', 'previous_login_ip', fn (Blueprint $table) => $table->string('previous_login_ip', 45)->nullable()->after('previous_login_at'));
        // Filled in at the end, once activity_logs has its event column.
        $this->backfillPasswordDates = $this->addColumn('users', 'password_changed_at', fn (Blueprint $table) => $table->timestamp('password_changed_at')->nullable()->after('password'));
        // New users get the User role unless told otherwise (was 100 in early builds).
        if ($this->columnDefault('users', 'rbac_id') !== '102') {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('rbac_id')->default(102)->change());
        }

        $this->createTable('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->index('user_id', 'personal_access_tokens_user_id_index');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->string('last_four', 4)->nullable();
            $table->longText('token_encrypted')->nullable();
            $table->text('abilities')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revoke_reason', 50)->nullable();
            $table->unsignedSmallInteger('expiry_notified_stage')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
        });
        $this->addColumn('personal_access_tokens', 'token_encrypted', fn (Blueprint $table) => $table->longText('token_encrypted')->nullable()->after('token'));
        $this->addColumn('personal_access_tokens', 'last_four', fn (Blueprint $table) => $table->string('last_four', 4)->nullable()->after('token'));
        $this->addColumn('personal_access_tokens', 'revoked_at', fn (Blueprint $table) => $table->timestamp('revoked_at')->nullable());
        $this->addColumn('personal_access_tokens', 'revoked_by_user_id', fn (Blueprint $table) => $table->unsignedBigInteger('revoked_by_user_id')->nullable());
        $this->addColumn('personal_access_tokens', 'revoke_reason', fn (Blueprint $table) => $table->string('revoke_reason', 50)->nullable());
        $this->addColumn('personal_access_tokens', 'expiry_notified_stage', fn (Blueprint $table) => $table->unsignedSmallInteger('expiry_notified_stage')->nullable());
        $this->addColumn('personal_access_tokens', 'expired_notified_at', fn (Blueprint $table) => $table->timestamp('expired_notified_at')->nullable());

        // API session tokens of /api/auth/login (sessions is the older name, kept for upgrades).
        $this->createTable('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'token']);
        });

        $this->createTable('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Laravel database sessions of the web console.
        $this->createTable('web_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    // ================================================================== 2. framework

    private function framework(): void
    {
        $this->createTable('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        $this->createTable('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        $this->createTable('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->createTable('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $this->createTable('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    // ================================================================== 3. settings and audit

    private function settingsAndAudit(): void
    {
        $this->createTable('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_group', 100)->default('general');
            $table->string('setting_key', 150)->unique();
            $table->longText('setting_value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->index(['setting_group', 'setting_key']);
        });

        $this->createTable('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->string('event', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('method', 10);
            $table->string('path', 512);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('request_payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $table->index('created_at', 'activity_logs_created_index');
            $table->index(['workspace_id', 'created_at'], 'activity_logs_workspace_created_index');
        });
        $this->addColumn('activity_logs', 'event', fn (Blueprint $table) => $table->string('event', 64)->nullable()->after('user_id'));
        $this->addColumn('activity_logs', 'description', fn (Blueprint $table) => $table->string('description', 255)->nullable()->after('event'));
        $this->addColumn('activity_logs', 'outcome', fn (Blueprint $table) => $table->string('outcome', 16)->nullable()->after('description'));
        $this->addIndex('activity_logs', 'activity_logs_user_created_index', fn (Blueprint $table) => $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index'));
        $this->addIndex('activity_logs', 'activity_logs_created_index', fn (Blueprint $table) => $table->index('created_at', 'activity_logs_created_index'));
        $addedWorkspace = $this->addColumn('activity_logs', 'workspace_id', fn (Blueprint $table) => $table->unsignedBigInteger('workspace_id')->nullable()->after('user_id'));
        $addedSystem = $this->addColumn('activity_logs', 'system_register_id', fn (Blueprint $table) => $table->unsignedBigInteger('system_register_id')->nullable()->after('workspace_id'));
        $this->addIndex('activity_logs', 'activity_logs_workspace_created_index', fn (Blueprint $table) => $table->index(['workspace_id', 'created_at'], 'activity_logs_workspace_created_index'));
        if (($addedWorkspace || $addedSystem) && Schema::hasTable('system_register')) {
            $this->backfillActivitySystems();
        }

        $this->createTable('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('subject', 255);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });
    }

    // ================================================================== 4. workspaces

    private function workspaces(): void
    {
        $this->createTable('workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->string('name', 255);
            $table->string('description', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'status']);
        });

        $this->createTable('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_admin')->default(false);
            // What a User-role workspace admin may change; null = Workspace::PERMISSIONS defaults.
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'is_admin']);
        });
        $this->addColumn('workspace_user', 'permissions', fn (Blueprint $table) => $table->json('permissions')->nullable()->after('is_admin'));

        $this->createTable('workspace_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->primary();
            $table->json('settings');
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        $this->createTable('workspace_notification_preferences', function (Blueprint $table) {
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

        $this->createTable('workspace_backup_runs', function (Blueprint $table) {
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

        // One row per Vulnerability Checks run, for the progress bar.
        $this->createTable('workspace_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('trigger', 16);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'id']);
        });
        $this->addColumn('workspace_ai_runs', 'cancelled_at', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable()->after('finished_at'));
        $this->addColumn('workspace_ai_runs', 'cancelled_by', fn (Blueprint $table) => $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at'));

        // workspace_id null = organization-level group (install-wide, super admin only).
        $this->createTable('notification_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('channel', 30);
            $table->string('name', 255);
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

    // ================================================================== 5. systems and configs

    private function systemsAndConfigs(): void
    {
        // id is a random 5–10 digit number (SystemRegister::generateUniqueId()).
        // user_id is the person who registered the system; service_account_id what it runs as.
        $this->createTable('system_register', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('pat_token_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_account_id')->nullable();
            $table->unsignedBigInteger('org_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->default(0);
            $table->string('system_name', 255);
            $table->string('os_type', 100);
            $table->string('ip_address', 45);
            $table->string('public_ip', 45)->nullable();
            $table->boolean('public_facing')->default(false);
            $table->text('description')->nullable();
            $table->string('distro', 100)->nullable();
            $table->string('version', 100)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->string('tags', 512)->nullable();
            $table->longText('metadata')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('validation_hash', 255)->nullable();
            $table->char('system_key_hash', 64)->nullable();
            $table->char('system_key_last_four', 4)->nullable();
            $table->timestamps();
            $table->index(['pat_token_id', 'user_id']);
            $table->index('workspace_id');
            $table->index('service_account_id');
            $table->index('system_key_hash', 'system_register_system_key_hash_index');
        });

        // user_id null = owned by a service account (service_account_id).
        $this->createTable('services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id', true);
            $table->string('service_name', 255);
            $table->unsignedBigInteger('system_id');
            $table->string('system_hash', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('service_account_id')->nullable();
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('share_with', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->foreign('system_id')->references('id')->on('system_register')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'system_id', 'service_name'], 'services_user_system_name_unique');
            $table->index(['system_id', 'status']);
            $table->index('service_account_id');
        });

        $this->createTable('configuration_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_account_id')->nullable();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('file_name', 255);
            $table->string('service_name', 255)->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('file_location', 512);
            $table->string('validation_hash', 255)->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'file_name']);
            $table->index('system_register_id');
            $table->index('service_id');
            $table->index('service_account_id');
            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
            $table->index(['system_register_id', 'service_name', 'content_sha256'], 'configuration_files_system_service_sha256_index');
        });

        $this->createTable('raw_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('configuration_files')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_account_id')->nullable();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->longText('file_data');
            $table->string('validation_hash', 255)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['file_id', 'user_id']);
            $table->index('system_register_id');
            $table->index('service_id');
            $table->index('service_account_id');
            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
        });

        // Upgrades: versions, service links, and the 2.1.2 service account columns.
        $this->addColumn('configuration_files', 'version', fn (Blueprint $table) => $table->string('version', 50)->nullable()->after('validation_hash'));
        $this->addColumn('raw_data', 'version', fn (Blueprint $table) => $table->string('version', 50)->nullable()->after('validation_hash'));
        foreach (['configuration_files', 'raw_data'] as $tableName) {
            $this->addColumn($tableName, 'service_id', function (Blueprint $table) use ($tableName) {
                $table->unsignedBigInteger('service_id')->nullable()->after('system_register_id');
                $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
                $table->index('service_id', $tableName . '_service_id_index');
            });
        }
        foreach (['system_register', 'configuration_files', 'raw_data', 'services'] as $tableName) {
            $this->addColumn($tableName, 'service_account_id', fn (Blueprint $table) => $table->unsignedBigInteger('service_account_id')->nullable()->after('user_id'));
            $this->addIndex($tableName, $tableName . '_service_account_id_index', fn (Blueprint $table) => $table->index('service_account_id'));
        }
        // Uploads by a service account have no user (2.1.2).
        foreach (['configuration_files', 'raw_data', 'services'] as $tableName) {
            if (!$this->columnNullable($tableName, 'user_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
            }
        }

        $this->createTable('config_ai_validations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('configuration_file_id')->index();
            // null for scheduled and upload-triggered reviews
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('trigger', 16)->default('manual');
            $table->string('provider', 100);
            $table->string('model', 200);
            $table->string('status', 16);
            $table->text('summary')->nullable();
            $table->json('result');
            $table->timestamps();
        });
        $this->addColumn('config_ai_validations', 'trigger', fn (Blueprint $table) => $table->string('trigger', 16)->default('manual')->after('user_id'));
        if (!$this->columnNullable('config_ai_validations', 'user_id')) {
            Schema::table('config_ai_validations', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
        }
    }

    // ================================================================== 6. service accounts

    private function serviceAccounts(): void
    {
        // A machine identity: one workspace (workspace scope) or granted workspaces of an
        // organization (organization scope). Keys are personal_access_tokens rows with
        // tokenable_type App\Models\ServiceAccount.
        $this->createTable('service_accounts', function (Blueprint $table) {
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

        $this->createTable('service_account_workspaces', function (Blueprint $table) {
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
        // Named explicitly: the generated name is 65 characters, over MySQL's 64.
        $this->addIndex('service_account_workspaces', 'sa_workspaces_account_workspace_unique', fn (Blueprint $table) => $table->unique(['service_account_id', 'workspace_id'], 'sa_workspaces_account_workspace_unique'));
    }

    // ================================================================== 7. temporary messages

    private function temporaryMessages(): void
    {
        $this->createTable('temporary_credential_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pat_token_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('service_account_id')->nullable()->index();
            $table->string('status')->default('active'); // active, read, expired
            $table->integer('days_left')->nullable();
            $table->integer('notice_stage')->nullable();
            $table->text('message');
            $table->string('severity'); // info, warn, error
            $table->string('credential_type'); // service_account, personal_access_token
            $table->string('owner');
            $table->timestamp('expires_at')->index(); // 12 hours from creation
            $table->timestamps();
        });
    }

    // ================================================================== 8. force lockout support (2026_10_16_000000)

    private function forceAttempts(): void
    {
        $this->createTable('force_attempts', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64); // sha256 of lowercase email
            $table->unsignedBigInteger('user_id')->nullable(); // The actual user if known
            $table->ipAddress('ip');
            $table->boolean('succeeded')->default(false);
            $table->timestamps();

            $table->index(['email_hash', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Add lockout tracking columns to users table
        $this->addColumn('users', 'force_locked_until', fn (Blueprint $table) => $table->dateTime('force_locked_until')->nullable()->after('status'));
        $this->addColumn('users', 'force_lock_level', fn (Blueprint $table) => $table->tinyInteger('force_lock_level')->default(0)->after('force_locked_until'));
        $this->addColumn('users', 'force_lock_level_at', fn (Blueprint $table) => $table->dateTime('force_lock_level_at')->nullable()->after('force_lock_level'));
        $this->addIndex('users', 'users_force_locked_until_index', fn (Blueprint $table) => $table->index('force_locked_until'));
    }

    // ================================================================== 9. security events (2026_10_16_000001)

    private function securityEvents(): void
    {
        $this->createTable('security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->string('type', 50); // force.failed, force.locked, force.unlocked, force.succeeded, key.revoked, key.expired_used, system.key_rotated, system.replaced, legacy_api.used
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('email_hash', 64)->nullable(); // sha256 of lowercase email
            $table->ipAddress('ip')->nullable();
            $table->json('details')->nullable(); // Additional context (no secrets)
            $table->timestamps();

            $table->index('org_id');
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    // ================================================================== 10. system key hash (2026_10_16_000002)

    private function systemKeyHash(): void
    {
        // Adds system_register.system_key_hash (sha256 hex, indexed) and system_key_last_four,
        // and fills them from the plaintext validation_hash.
        if (!Schema::hasColumn('system_register', 'system_key_hash')) {
            Schema::table('system_register', function (Blueprint $table) {
                $table->char('system_key_hash', 64)->nullable()->after('validation_hash');
                $table->char('system_key_last_four', 4)->nullable()->after('system_key_hash');
                $table->index('system_key_hash', 'system_register_system_key_hash_index');
            });
        }

        $this->backfillSystemKeyHash();
    }

    // ================================================================== 11. config content sha256 (2026_10_16_000003)

    private function configContentSha256(): void
    {
        // Adds sha256 of each config version's stored bytes, so the CLI can compare without
        // downloading. Nullable: null means "not known yet" (filled on upload, and for older rows
        // by `php artisan atglance:config-hash-backfill`).
        if (Schema::hasColumn('configuration_files', 'content_sha256')) {
            return;
        }

        Schema::table('configuration_files', function (Blueprint $table) {
            $table->char('content_sha256', 64)->nullable()->after('validation_hash');
            $table->index(['system_register_id', 'service_name', 'content_sha256'], 'configuration_files_system_service_sha256_index');
        });
    }

    // ================================================================== 12. starting rows and data

    private function startingRowsAndData(): void
    {
        $now = now();

        foreach ([
            [100, 'super_admin', true, true, true],
            [101, 'admin', true, true, true],
            [102, 'user', true, false, false],
        ] as [$id, $name, $read, $write, $execute]) {
            DB::table('rbac')->insertOrIgnore([
                'id' => $id, 'role_name' => $name, 'read' => $read, 'write' => $write, 'execute' => $execute,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (DB::table('organizations')->count() === 0) {
            DB::table('organizations')->insert([
                'id' => 200,
                'name' => 'Default Organization',
                'description' => 'Default organization for the system',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('admin_settings')->insertOrIgnore([
            'setting_group' => 'mcp', 'setting_key' => 'mcp_enabled', 'setting_value' => 'false',
            'is_encrypted' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Readable id ranges on MySQL, only for tables this run created.
        if (DB::getDriverName() === 'mysql') {
            foreach (['users' => 1010, 'services' => 100, 'configuration_files' => 2010] as $tableName => $start) {
                if (in_array($tableName, $this->created, true)) {
                    DB::statement("ALTER TABLE {$tableName} AUTO_INCREMENT = {$start}");
                }
            }
        }

        if ($this->backfillPasswordDates) {
            $this->backfillPasswordChangedAt();
        }

        // 2.1.2: keys are stored as a hash only; drop the recoverable copies.
        DB::table('personal_access_tokens')->whereNotNull('token_encrypted')->update(['token_encrypted' => null]);

        // 2.1.2: reactivations have their own event. Every list that had system.registered
        // (which used to carry them) also gets system.reactivated.
        $this->subscribeReactivated();
    }

    // ================================================================== helpers

    private function createTable(string $name, Closure $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
            $this->created[] = $name;
        }
    }

    /**
     * Adds a column (and whatever goes with it) when the table lacks it. Returns whether
     * it was added, so a data fix can run only then.
     */
    private function addColumn(string $table, string $column, Closure $definition): bool
    {
        if (in_array($table, $this->created, true) || Schema::hasColumn($table, $column)) {
            return false;
        }

        Schema::table($table, $definition);

        return true;
    }

    private function addIndex(string $table, string $index, Closure $definition): void
    {
        if (!Schema::hasIndex($table, $index)) {
            Schema::table($table, $definition);
        }
    }

    private function column(string $table, string $column): ?array
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return $info;
            }
        }

        return null;
    }

    private function columnNullable(string $table, string $column): bool
    {
        return (bool) ($this->column($table, $column)['nullable'] ?? false);
    }

    private function columnDefault(string $table, string $column): ?string
    {
        $default = $this->column($table, $column)['default'] ?? null;

        return $default === null ? null : trim((string) $default, "'\"");
    }

    /**
     * Best guess for accounts that existed before password_changed_at: the latest
     * password-change request, else the account creation time.
     */
    private function backfillPasswordChangedAt(): void
    {
        foreach (DB::table('users')->select('id', 'created_at')->get() as $user) {
            $changedAt = DB::table('activity_logs')
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query
                    ->where('event', 'password.changed')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('event')->where('method', 'POST')->where('path', '/password/update')))
                ->max('created_at');
            DB::table('users')->where('id', $user->id)->update(['password_changed_at' => $changedAt ?? $user->created_at]);
        }
    }

    /**
     * Links existing deregister and reactivate log entries to their system and workspace,
     * so they show on the workspace's Recent Activity tab.
     */
    private function backfillActivitySystems(): void
    {
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

    /**
     * Fills the hash columns of rows that have a key but no hash yet. Chunked by id and
     * safe to run again: rows already done, and rows without a key, are left alone.
     */
    private function backfillSystemKeyHash(): void
    {
        DB::table('system_register')
            ->whereNull('system_key_hash')
            ->whereNotNull('validation_hash')
            ->where('validation_hash', '<>', '')
            ->select(['id', 'validation_hash'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $key = (string) $row->validation_hash;
                    DB::table('system_register')->where('id', $row->id)->update([
                        'system_key_hash' => hash('sha256', $key),
                        'system_key_last_four' => mb_strlen($key) < 12 ? null : mb_substr($key, -4),
                    ]);
                }
            });
    }

    private function subscribeReactivated(): void
    {
        foreach (DB::table('notification_groups')->get(['id', 'events']) as $group) {
            if ($events = $this->withReactivated(json_decode((string) $group->events, true))) {
                DB::table('notification_groups')->where('id', $group->id)->update(['events' => json_encode($events)]);
            }
        }

        foreach (DB::table('workspace_settings')->get(['workspace_id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            if (!is_array($settings)) {
                continue;
            }
            $changed = false;
            foreach (['events', 'member_email_default_events'] as $key) {
                if ($events = $this->withReactivated($settings[$key] ?? null)) {
                    $settings[$key] = $events;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('workspace_settings')->where('workspace_id', $row->workspace_id)->update(['settings' => json_encode($settings)]);
            }
        }

        foreach (DB::table('workspace_notification_preferences')->get(['id', 'events']) as $row) {
            if ($events = $this->withReactivated(json_decode((string) $row->events, true))) {
                DB::table('workspace_notification_preferences')->where('id', $row->id)->update(['events' => json_encode($events)]);
            }
        }
    }

    /**
     * The list with system.reactivated added, or null when nothing changes.
     */
    private function withReactivated(mixed $events): ?array
    {
        if (!is_array($events) || !in_array('system.registered', $events, true) || in_array('system.reactivated', $events, true)) {
            return null;
        }

        $events[] = 'system.reactivated';

        return array_values($events);
    }
};
