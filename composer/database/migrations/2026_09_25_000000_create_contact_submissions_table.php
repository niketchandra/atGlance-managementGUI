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
        if (!Schema::hasTable('contact_submissions')) {
            Schema::create('contact_submissions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('email', 255);
                $table->string('subject', 255);
                $table->text('message');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
