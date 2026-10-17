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
        // Create force_attempts table to track failed attempts per email hash
        Schema::create('force_attempts', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64); // sha256 of lowercase email
            $table->unsignedBigInteger('user_id')->nullable(); // The actual user if known
            $table->ipAddress('ip');
            $table->boolean('succeeded')->default(false);
            $table->timestamps();

            $table->index(['email_hash', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Add lockout tracking columns to users table
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('force_locked_until')->nullable()->after('status');
            $table->tinyInteger('force_lock_level')->default(0)->after('force_locked_until');
            $table->dateTime('force_lock_level_at')->nullable()->after('force_lock_level');

            $table->index('force_locked_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_force_locked_until_index');
            $table->dropColumn(['force_locked_until', 'force_lock_level', 'force_lock_level_at']);
        });

        Schema::dropIfExists('force_attempts');
    }
};
