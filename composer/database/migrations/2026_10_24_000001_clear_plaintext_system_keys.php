<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 5e: system keys are kept only as hashes. The model no longer writes the plaintext
 * validation_hash, but rows saved before that still hold it. This hashes any row that has no
 * hash yet, then clears the plaintext of every row. Chunked by id and safe to run again.
 * Not reversible: the plaintext is gone (hosts keep their own key).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_register')
            ->whereNotNull('validation_hash')
            ->select(['id', 'validation_hash', 'system_key_hash'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $key = (string) $row->validation_hash;
                    $update = ['validation_hash' => null];
                    if (empty($row->system_key_hash) && $key !== '') {
                        $update['system_key_hash'] = hash('sha256', $key);
                        $update['system_key_last_four'] = mb_strlen($key) < 12 ? null : mb_substr($key, -4);
                    }
                    DB::table('system_register')->where('id', $row->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        // The plaintext keys cannot be restored.
    }
};
