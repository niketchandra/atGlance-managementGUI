<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('configuration_files')) {
            DB::statement('ALTER TABLE configuration_files AUTO_INCREMENT = 2010');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('configuration_files')) {
            DB::statement('ALTER TABLE configuration_files AUTO_INCREMENT = 1');
        }
    }
};