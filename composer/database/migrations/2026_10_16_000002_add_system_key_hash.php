<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 5a: system keys stored as hashes. Adds system_register.system_key_hash (sha256 hex,
 * indexed) and system_key_last_four, and fills them from the plaintext validation_hash.
 * validation_hash stays and is still written (Task 5e drops it after a release).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('system_register', 'system_key_hash')) {
            Schema::table('system_register', function (Blueprint $table) {
                $table->char('system_key_hash', 64)->nullable()->after('validation_hash');
                $table->char('system_key_last_four', 4)->nullable()->after('system_key_hash');
                $table->index('system_key_hash', 'system_register_system_key_hash_index');
            });
        }

        self::backfill();
    }

    public function down(): void
    {
        if (!Schema::hasColumn('system_register', 'system_key_hash')) {
            return;
        }

        Schema::table('system_register', function (Blueprint $table) {
            $table->dropIndex('system_register_system_key_hash_index');
            $table->dropColumn(['system_key_hash', 'system_key_last_four']);
        });
    }

    /**
     * Fills the hash columns of rows that have a key but no hash yet. Chunked by id and
     * safe to run again: rows already done, and rows without a key, are left alone.
     */
    public static function backfill(): void
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
};
