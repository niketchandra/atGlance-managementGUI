<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix CRIT-001: Change token expiry default from 2099-12-31 to NULL
        // Tokens created via UserKeys service already set proper 90-day expiry
        // This prevents accidental indefinite expiry for tokens created outside normal flow
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to previous default
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->default('2099-12-31 23:59:59')->change();
        });
    }
};
