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
        // Tables already created by consolidated schema, skip if they exist
        if (!Schema::hasTable('rbac')) {
            Schema::create('rbac', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('role_name', 50)->unique();
                $table->boolean('read')->default(false);
                $table->boolean('write')->default(false);
                $table->boolean('execute')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name', 255);
                $table->string('description', 512)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        // Add rbac_id and org_id to users table if not already present
        if (!Schema::hasColumn('users', 'rbac_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('rbac_id')->default(102)->after('id');
                $table->unsignedBigInteger('org_id')->default(200)->after('rbac_id');

                $table->foreign('rbac_id')->references('id')->on('rbac')->onDelete('cascade');
                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            });
        }

        // Insert default RBAC roles if not present
        if (DB::table('rbac')->count() === 0) {
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
        }

        // Insert default organization if not present
        if (DB::table('organizations')->count() === 0) {
            DB::table('organizations')->insert([
                'id' => 200,
                'name' => 'Default Organization',
                'description' => 'Default organization for the system',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
