<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fully consolidated migration containing the final schema.
     */
    public function up(): void
    {
        Schema::create('rbac', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('role_name', 50)->unique();
            $table->boolean('read')->default(false);
            $table->boolean('write')->default(false);
            $table->boolean('execute')->default(false);
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 255);
            $table->string('description', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
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
            $table->rememberToken();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('rbac_id')->references('id')->on('rbac')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
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
            $table->longText('token_encrypted')->nullable();
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

        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
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

        Schema::create('system_register', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('pat_token_id');
            $table->unsignedBigInteger('user_id');
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
            $table->timestamps();
            $table->index(['pat_token_id', 'user_id']);
            $table->index('workspace_id');
        });

        Schema::create('services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id', true);
            $table->string('service_name', 255);
            $table->unsignedBigInteger('system_id');
            $table->string('system_hash', 255)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('share_with', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('system_register')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'system_id', 'service_name'], 'services_user_system_name_unique');
            $table->index(['system_id', 'status']);
        });

        Schema::create('configuration_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('system_register_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('file_name', 255);
            $table->string('service_name', 255)->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('file_location', 512);
            $table->string('validation_hash', 255)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'file_name']);
            $table->index('system_register_id');
            $table->index('service_id');

            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
        });

        Schema::create('raw_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('configuration_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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

            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 512);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('request_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_group', 100)->default('general');
            $table->string('setting_key', 150)->unique();
            $table->longText('setting_value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->index(['setting_group', 'setting_key']);
        });

        DB::table('rbac')->insert([
            [
                'id' => 100,
                'role_name' => 'super_admin',
                'read' => true,
                'write' => true,
                'execute' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 101,
                'role_name' => 'admin',
                'read' => true,
                'write' => true,
                'execute' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 102,
                'role_name' => 'user',
                'read' => true,
                'write' => false,
                'execute' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('organizations')->insert([
            'id' => 200,
            'name' => 'Default Organization',
            'description' => 'Default organization for the system',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users AUTO_INCREMENT = 1010');
            DB::statement('ALTER TABLE services AUTO_INCREMENT = 100');
            DB::statement('ALTER TABLE configuration_files AUTO_INCREMENT = 2010');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('raw_data');
        Schema::dropIfExists('configuration_files');
        Schema::dropIfExists('services');
        Schema::dropIfExists('system_register');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('session_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('rbac');
    }
};
