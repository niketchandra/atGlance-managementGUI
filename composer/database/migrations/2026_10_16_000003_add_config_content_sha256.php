<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 10: sha256 of each config version's stored bytes, so the CLI can compare without
 * downloading. Nullable: null means "not known yet" (filled on upload, and for older rows
 * by `php artisan atglance:config-hash-backfill`). Indexed with the system and service it
 * is looked up by.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('configuration_files', 'content_sha256')) {
            return;
        }

        Schema::table('configuration_files', function (Blueprint $table) {
            $table->char('content_sha256', 64)->nullable()->after('validation_hash');
            $table->index(['system_register_id', 'service_name', 'content_sha256'], 'configuration_files_system_service_sha256_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('configuration_files', 'content_sha256')) {
            return;
        }

        Schema::table('configuration_files', function (Blueprint $table) {
            $table->dropIndex('configuration_files_system_service_sha256_index');
            $table->dropColumn('content_sha256');
        });
    }
};
