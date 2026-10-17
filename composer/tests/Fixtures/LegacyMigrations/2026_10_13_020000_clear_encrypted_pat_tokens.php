<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * From 2.1.2 keys are stored as a hash only. Remove the recoverable copies kept for
     * the old "view key" button. The keys keep working: their hash is unchanged.
     */
    public function up(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'token_encrypted')) {
            DB::table('personal_access_tokens')->whereNotNull('token_encrypted')->update(['token_encrypted' => null]);
        }
    }

    public function down(): void
    {
        // The plaintext cannot be restored.
    }
};
