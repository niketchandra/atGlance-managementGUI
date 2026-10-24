<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The 2.1.2 consolidated migration must give exactly the schema the 29 old migrations
 * gave (tests/Fixtures/LegacyMigrations), on a fresh install and when upgrading from
 * 1.x (migrations up to 2026-04-09) and 2.0 to 2.1.1 (up to 2026-10-07).
 */
class ConsolidatedMigrationTest extends TestCase
{
    private const LAST_1X = '2026_04_09_000001_set_configuration_files_autoincrement_to_2010.php';
    private const LAST_2X = '2026_10_07_000000_add_workspace_to_activity_logs.php';
    /** The consolidated 2.1.2 schema; later migrations (lockout, key hashes, ...) add to it. */
    private const CONSOLIDATED = '2026_10_14_000000_version_2_1_2_schema.php';
    /** Tables new in 2.1.2, created only by the consolidated migration (no legacy migration). */
    private const NEW_IN_2_1_2 = ['temporary_credential_messages'];

    private string $legacyDir;
    private string $newDir;
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyDir = base_path('tests/Fixtures/LegacyMigrations');
        $this->newDir = database_path('migrations');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_fresh_install_matches_legacy_migrations(): void
    {
        $this->migrateFresh($this->legacyDir);
        $legacy = $this->snapshot();

        $this->migrateFresh($this->subset($this->newDir, self::CONSOLIDATED));
        $consolidated = $this->snapshot();
        foreach (self::NEW_IN_2_1_2 as $table) {
            $this->assertArrayHasKey($table, $consolidated);
            unset($consolidated[$table]);
        }

        $this->assertEquals($legacy, $consolidated);
    }

    public function test_upgrade_from_2x_matches_fresh_install(): void
    {
        $this->assertUpgradeMatchesFresh(self::LAST_2X);
    }

    public function test_upgrade_from_1x_matches_fresh_install(): void
    {
        $this->assertUpgradeMatchesFresh(self::LAST_1X);
    }

    public function test_upgrade_from_1x_keeps_data_and_fills_new_columns(): void
    {
        $this->migrateFresh($this->legacySubset(self::LAST_1X));
        DB::table('users')->insert([
            'id' => 1010, 'name' => 'Old', 'email' => 'old@example.test', 'password_hash' => 'x',
            'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 10:00:00',
        ]);
        DB::table('activity_logs')->insert([
            'user_id' => 1010, 'method' => 'POST', 'path' => '/password/update',
            'created_at' => '2026-06-02 09:00:00', 'updated_at' => '2026-06-02 09:00:00',
        ]);

        Artisan::call('migrate', ['--path' => $this->newDir, '--realpath' => true, '--force' => true]);

        $user = DB::table('users')->where('id', 1010)->first();
        $this->assertSame('old@example.test', $user->email);
        $this->assertSame('2026-06-02 09:00:00', (string) $user->password_changed_at);
        $this->assertSame(3, DB::table('rbac')->count());
        $this->assertSame(1, DB::table('organizations')->where('id', 200)->count());
        $this->assertSame('false', DB::table('admin_settings')->where('setting_key', 'mcp_enabled')->value('setting_value'));
    }

    public function test_fresh_install_has_starting_rows(): void
    {
        $this->migrateFresh($this->newDir);

        $this->assertSame([100, 101, 102], DB::table('rbac')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('Default Organization', DB::table('organizations')->where('id', 200)->value('name'));
        $this->assertSame('false', DB::table('admin_settings')->where('setting_key', 'mcp_enabled')->value('setting_value'));
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->migrateFresh($this->newDir);
        $first = $this->snapshot();

        (require database_path('migrations/2026_10_14_000000_version_2_1_2_schema.php'))->up();

        $this->assertEquals($first, $this->snapshot());
        $this->assertSame(3, DB::table('rbac')->count());
    }

    private function assertUpgradeMatchesFresh(string $lastLegacy): void
    {
        $this->migrateFresh($this->newDir);
        $fresh = $this->snapshot();

        $this->migrateFresh($this->legacySubset($lastLegacy));
        Artisan::call('migrate', ['--path' => $this->newDir, '--realpath' => true, '--force' => true]);

        $this->assertEquals($fresh, $this->snapshot());
    }

    private function migrateFresh(string $path): void
    {
        Artisan::call('migrate:fresh', ['--path' => $path, '--realpath' => true, '--force' => true]);
    }

    /**
     * A folder with the legacy migrations up to and including $lastFile.
     */
    private function legacySubset(string $lastFile): string
    {
        return $this->subset($this->legacyDir, $lastFile);
    }

    /**
     * A folder with the migrations of $source up to and including $lastFile.
     */
    private function subset(string $source, string $lastFile): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atglance-migrations-' . uniqid();
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        foreach (File::files($source) as $file) {
            if (strcmp($file->getFilename(), $lastFile) <= 0) {
                File::copy($file->getPathname(), $dir . DIRECTORY_SEPARATOR . $file->getFilename());
            }
        }

        return $dir;
    }

    /**
     * Every table's columns, indexes and foreign keys, order-independent.
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(): array
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->reject(fn ($name) => in_array($name, ['migrations', 'sqlite_sequence'], true))
            ->sort()->values();

        $snapshot = [];
        foreach ($tables as $table) {
            $columns = collect(Schema::getColumns($table))
                ->map(fn ($c) => [$c['name'], $c['type_name'], (bool) $c['nullable'], $c['default'] === null ? null : (string) $c['default'], (bool) $c['auto_increment']])
                ->sortBy(0)->values()->all();
            $indexes = collect(Schema::getIndexes($table))
                ->map(fn ($i) => [$i['name'], implode(',', $i['columns']), (bool) $i['unique'], (bool) $i['primary']])
                ->sortBy(0)->values()->all();
            $foreignKeys = collect(Schema::getForeignKeys($table))
                ->map(fn ($f) => implode(',', $f['columns']) . '->' . $f['foreign_table'] . '(' . implode(',', $f['foreign_columns']) . ') on delete ' . strtolower((string) $f['on_delete']))
                ->sort()->values()->all();

            $snapshot[$table] = compact('columns', 'indexes', 'foreignKeys');
        }

        return $snapshot;
    }
}
