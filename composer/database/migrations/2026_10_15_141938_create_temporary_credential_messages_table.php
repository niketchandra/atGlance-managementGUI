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
        Schema::create('temporary_credential_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pat_token_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('service_account_id')->nullable()->index();
            $table->string('status')->default('active'); // active, read, expired
            $table->integer('days_left')->nullable();
            $table->integer('notice_stage')->nullable();
            $table->text('message');
            $table->string('severity'); // info, warn, error
            $table->string('credential_type'); // service_account, personal_access_token
            $table->string('owner');
            $table->timestamp('expires_at')->index(); // 12 hours from creation
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_credential_messages');
    }
};
