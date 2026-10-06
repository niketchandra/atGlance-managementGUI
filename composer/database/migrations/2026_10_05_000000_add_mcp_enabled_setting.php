<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_settings')->insertOrIgnore([
            'setting_group' => 'mcp',
            'setting_key' => 'mcp_enabled',
            'setting_value' => 'false',
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_settings')->where('setting_key', 'mcp_enabled')->delete();
    }
};
