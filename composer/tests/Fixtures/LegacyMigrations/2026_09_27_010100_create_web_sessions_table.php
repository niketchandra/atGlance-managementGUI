<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's database session store, so a user can list and end their
     * browser sessions. The older `sessions` table has a different, unused
     * layout, so this uses its own name (config/session.php "table").
     */
    public function up(): void
    {
        if (Schema::hasTable('web_sessions')) {
            return;
        }

        Schema::create('web_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sessions');
    }
};
