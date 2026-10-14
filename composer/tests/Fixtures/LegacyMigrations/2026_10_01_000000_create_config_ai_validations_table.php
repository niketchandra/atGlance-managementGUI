<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved "Validate with AI" results, so a configuration file's review
     * history can be reopened and shared without calling the AI again.
     */
    public function up(): void
    {
        Schema::create('config_ai_validations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('configuration_file_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider', 100);
            $table->string('model', 200);
            $table->string('status', 16);
            $table->text('summary')->nullable();
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_ai_validations');
    }
};
