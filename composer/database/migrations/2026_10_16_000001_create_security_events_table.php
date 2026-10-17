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
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('org_id');
            $table->string('type', 50); // force.failed, force.locked, force.unlocked, force.succeeded, key.revoked, key.expired_used, system.key_rotated, system.replaced, legacy_api.used
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('email_hash', 64)->nullable(); // sha256 of lowercase email
            $table->ipAddress('ip')->nullable();
            $table->json('details')->nullable(); // Additional context (no secrets)
            $table->timestamps();

            $table->index('org_id');
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
