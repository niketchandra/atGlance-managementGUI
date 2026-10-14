<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The service account migration on MySQL: identifier length (MySQL allows 64, SQLite
 * has no limit, so the suite alone never caught it) and re-running after a partial
 * apply (MySQL DDL is not transactional, so a failed run leaves tables behind).
 */
class ServiceAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_names_fit_mysql_limit(): void
    {
        $names = collect(DB::select("select name from sqlite_master where type = 'index' and tbl_name in ('service_accounts', 'service_account_workspaces', 'personal_access_tokens', 'system_register', 'configuration_files', 'raw_data', 'services') and name not like 'sqlite_%'"))
            ->pluck('name');

        $this->assertContains('sa_workspaces_account_workspace_unique', $names->all());
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "Index name too long for MySQL: {$name}");
        }
    }

    public function test_migration_completes_when_rerun_after_partial_apply(): void
    {
        $migration = require database_path('migrations/2026_10_13_000000_create_service_accounts.php');

        // Already applied by RefreshDatabase: a second run must finish, as it must after
        // a MySQL run that stopped halfway.
        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('personal_access_tokens', 'last_four'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('services', 'service_account_id'));
    }
}
