<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create RBAC table
        Schema::create('rbac', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('role_name', 50)->unique();
            $table->boolean('read')->default(false);
            $table->boolean('write')->default(false);
            $table->boolean('execute')->default(false);
            $table->timestamps();
        });

        // Create organizations table
        Schema::create('organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 255);
            $table->string('description', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        // Add rbac_id and org_id to users table
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('rbac_id')->default(102)->after('id');
            $table->unsignedBigInteger('org_id')->default(200)->after('rbac_id');
            
            $table->foreign('rbac_id')->references('id')->on('rbac')->onDelete('cascade');
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        // Insert default RBAC roles
        DB::table('rbac')->insert([
            [
                'id' => 100,
                'role_name' => 'super_admin',
                'read' => true,
                'write' => true,
                'execute' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 101,
                'role_name' => 'admin',
                'read' => true,
                'write' => true,
                'execute' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 102,
                'role_name' => 'user',
                'read' => true,
                'write' => false,
                'execute' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Insert default organization
        DB::table('organizations')->insert([
            'id' => 200,
            'name' => 'Default Organization',
            'description' => 'Default organization for the system',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Set user ID auto-increment to start from 1010
        DB::statement('ALTER TABLE users AUTO_INCREMENT = 1010');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['rbac_id']);
            $table->dropForeign(['org_id']);
            $table->dropColumn(['rbac_id', 'org_id']);
        });

        Schema::dropIfExists('organizations');
        Schema::dropIfExists('rbac');
    }
};
