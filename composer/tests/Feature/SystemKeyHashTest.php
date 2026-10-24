<?php

namespace Tests\Feature;

use App\Models\SystemRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 5: system keys are stored as sha256 hashes (system_key_hash) with their last four
 * characters for display. Task 5e: the plaintext validation_hash is no longer kept.
 */
class SystemKeyHashTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'k1-0123456789abcdef0123456789abcdef01234567';
    private const KEY_2 = 'k1-fedcba9876543210fedcba9876543210fedcba98';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_16_000002_add_system_key_hash.php');
    }

    private function rawSystem(int $id, ?string $key, array $extra = []): void
    {
        DB::table('system_register')->insert($extra + [
            'id' => $id, 'pat_token_id' => 1, 'user_id' => 1, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'host-' . $id, 'os_type' => 'linux', 'ip_address' => '10.0.0.1',
            'validation_hash' => $key, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- 5a: columns, backfill, model

    public function test_backfill_fills_rows_with_a_key_and_leaves_rows_without_one(): void
    {
        // Inserted without the model, as rows written before the migration were.
        $this->rawSystem(10001, self::KEY);
        $this->rawSystem(10002, null);
        $this->rawSystem(10003, '');
        $this->rawSystem(10004, 'short-key');

        $this->migration()::backfill();

        $row = DB::table('system_register')->where('id', 10001)->first();
        $this->assertSame(hash('sha256', self::KEY), $row->system_key_hash);
        $this->assertSame('4567', $row->system_key_last_four);
        $this->assertSame(self::KEY, $row->validation_hash, 'plaintext stays until 5e');

        foreach ([10002, 10003] as $id) {
            $row = DB::table('system_register')->where('id', $id)->first();
            $this->assertNull($row->system_key_hash);
            $this->assertNull($row->system_key_last_four);
        }

        // A key under 12 characters is hashed but shows no characters.
        $row = DB::table('system_register')->where('id', 10004)->first();
        $this->assertSame(hash('sha256', 'short-key'), $row->system_key_hash);
        $this->assertNull($row->system_key_last_four);
    }

    public function test_backfill_is_idempotent_and_skips_rows_that_have_a_hash(): void
    {
        $this->rawSystem(10001, self::KEY);
        $this->rawSystem(10002, self::KEY_2, ['system_key_hash' => str_repeat('a', 64), 'system_key_last_four' => 'zzzz']);

        $this->migration()::backfill();
        $this->migration()::backfill();

        $this->assertSame(hash('sha256', self::KEY), DB::table('system_register')->where('id', 10001)->value('system_key_hash'));
        $this->assertSame(str_repeat('a', 64), DB::table('system_register')->where('id', 10002)->value('system_key_hash'));
    }

    public function test_migration_rolls_back_and_runs_again(): void
    {
        $this->rawSystem(10001, self::KEY);
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('system_register', 'system_key_hash'));
        $this->assertFalse(Schema::hasColumn('system_register', 'system_key_last_four'));
        $this->assertSame(self::KEY, DB::table('system_register')->where('id', 10001)->value('validation_hash'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('system_register', 'system_key_hash'));
        $this->assertSame(hash('sha256', self::KEY), DB::table('system_register')->where('id', 10001)->value('system_key_hash'));
    }

    public function test_every_model_write_of_the_key_writes_hash_and_last_four(): void
    {
        $system = SystemRegister::create([
            'pat_token_id' => 1, 'user_id' => 1, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'web-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.5',
            'validation_hash' => self::KEY,
        ]);
        $row = DB::table('system_register')->where('id', $system->id)->first();
        $this->assertSame(hash('sha256', self::KEY), $row->system_key_hash);
        $this->assertSame('4567', $row->system_key_last_four);

        $system->validation_hash = self::KEY_2;
        $system->save();
        $row = DB::table('system_register')->where('id', $system->id)->first();
        $this->assertSame(hash('sha256', self::KEY_2), $row->system_key_hash);
        $this->assertSame('ba98', $row->system_key_last_four);

        $system->update(['validation_hash' => '']); // an empty key clears the hash
        $row = DB::table('system_register')->where('id', $system->id)->first();
        $this->assertNull($row->system_key_hash);
        $this->assertNull($row->system_key_last_four);
    }

    public function test_hash_columns_cannot_be_mass_assigned_out_of_step(): void
    {
        $system = SystemRegister::create([
            'pat_token_id' => 1, 'user_id' => 1, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'web-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.5',
            'validation_hash' => self::KEY, 'system_key_hash' => str_repeat('0', 64), 'system_key_last_four' => 'nope',
        ]);

        $this->assertSame(hash('sha256', self::KEY), $system->fresh()->system_key_hash);
        $this->assertSame('4567', $system->fresh()->system_key_last_four);
    }

    public function test_matches_key_uses_the_hash_and_falls_back_for_rows_without_one(): void
    {
        $this->rawSystem(10001, self::KEY);
        $legacy = SystemRegister::find(10001);
        $this->assertNull($legacy->system_key_hash);
        $this->assertTrue($legacy->matchesKey(self::KEY));
        $this->assertFalse($legacy->matchesKey(self::KEY_2));
        $this->assertFalse($legacy->matchesKey(''));

        // The hash wins over the plaintext column once it is set.
        DB::table('system_register')->where('id', 10001)->update(['system_key_hash' => hash('sha256', self::KEY_2)]);
        $hashed = SystemRegister::find(10001);
        $this->assertTrue($hashed->matchesKey(self::KEY_2));
        $this->assertFalse($hashed->matchesKey(self::KEY));

        $this->rawSystem(10002, null);
        $this->assertFalse(SystemRegister::find(10002)->matchesKey(''));
    }

    public function test_where_system_key_finds_hashed_and_not_yet_hashed_rows(): void
    {
        $this->rawSystem(10001, self::KEY);
        $this->rawSystem(10002, self::KEY_2);
        $this->migration()::backfill();
        $this->rawSystem(10003, self::KEY); // written after the backfill, no hash yet

        $this->assertEqualsCanonicalizing([10001, 10003], SystemRegister::query()->whereSystemKey(self::KEY)->pluck('id')->all());
        $this->assertSame([10002], SystemRegister::query()->whereSystemKey(self::KEY_2)->pluck('id')->all());
        $this->assertSame([], SystemRegister::query()->whereSystemKey('k1-unknown-key-0000000000000000000000')->pluck('id')->all());
    }
}
