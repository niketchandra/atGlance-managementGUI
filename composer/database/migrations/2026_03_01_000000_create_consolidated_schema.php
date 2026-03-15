<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated from the following migrations:
     * - 0001_01_01_000000_create_users_table.php
     * - 0001_01_01_000001_create_cache_table.php
     * - 0001_01_01_000002_create_jobs_table.php
     * - 2026_02_14_000001_create_personal_access_tokens_table.php
     * - 2026_02_14_000002_create_products_table.php (later dropped)
     * - 2026_02_14_000003_add_user_id_to_personal_access_tokens_table.php
     * - 2026_02_14_000004_set_default_expires_at_personal_access_tokens_table.php
     * - 2026_02_14_000005_backfill_expires_at_personal_access_tokens_table.php
     * - 2026_02_14_000006_add_dob_to_users_table.php
     * - 2026_02_14_000007_create_configuration_files_table.php
     * - 2026_02_14_000008_create_raw_data_table.php
     * - 2026_02_14_000009_drop_products_table.php
     * - 2026_02_14_000010_drop_password_reset_and_sessions_tables.php
     * - 2026_02_14_164919_create_sessions_table.php
     * - 2026_02_15_000001_create_system_register_table.php
     * - 2026_02_28_000001_add_file_name_to_raw_data_table.php
     * - 2026_02_28_000002_modify_system_register_use_random_id.php
     * - 2026_02_28_000003_add_system_register_id_and_server_name_to_configuration_files.php
     * - 2026_02_28_000004_add_system_register_id_and_server_name_to_raw_data.php
     * - 2026_02_28_000005_add_validation_hash_to_system_register_table.php
     * - 2026_02_28_000005_rename_server_name_to_service_name_in_configuration_files.php
     * - 2026_02_28_000006_rename_server_name_to_service_name_in_raw_data.php
     * - 2026_02_28_000007_add_validation_hash_to_configuration_files_table.php
     * - 2026_02_28_000008_add_validation_hash_to_raw_data_table.php
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->date('dob')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash');
            $table->rememberToken();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
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

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->index('user_id', 'personal_access_tokens_user_id_index');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable()->default('2099-12-31 23:59:59');
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'token']);
        });

        Schema::create('system_register', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('pat_token_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('system_name', 255);
            $table->string('os_type', 100);
            $table->string('ip_address', 45);
            $table->string('tags', 512)->nullable();
            $table->longText('metadata')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('validation_hash', 255)->nullable();
            $table->timestamps();
            $table->index(['pat_token_id', 'user_id']);
        });

        Schema::create('configuration_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->string('file_name', 255);
            $table->string('service_name', 255)->nullable();
            $table->string('file_location', 512);
            $table->string('validation_hash', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'file_name']);
            $table->index('system_register_id');
        });

        Schema::create('raw_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('configuration_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->longText('file_data');
            $table->string('validation_hash', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['file_id', 'user_id']);
            $table->index('system_register_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_data');
        Schema::dropIfExists('configuration_files');
        Schema::dropIfExists('system_register');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('users');
    }
};
