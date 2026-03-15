<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id', true);
            $table->string('service_name', 255);
            $table->unsignedBigInteger('system_id');
            $table->string('system_hash', 255)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('share_with', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('system_register')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'system_id', 'service_name'], 'services_user_system_name_unique');
            $table->index(['system_id', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE services AUTO_INCREMENT = 100');
        }

        Schema::table('configuration_files', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('system_register_id');
            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
            $table->index('service_id');
        });

        Schema::table('raw_data', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('system_register_id');
            $table->foreign('service_id')->references('service_id')->on('services')->nullOnDelete();
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_data', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropIndex(['service_id']);
            $table->dropColumn('service_id');
        });

        Schema::table('configuration_files', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropIndex(['service_id']);
            $table->dropColumn('service_id');
        });

        Schema::dropIfExists('services');
    }
};
