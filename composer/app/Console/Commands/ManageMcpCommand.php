<?php

namespace App\Console\Commands;

use App\Models\AdminSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ManageMcpCommand extends Command
{
    protected $signature = 'mcp:manage';
    protected $description = 'Start or stop MCP container based on site setting';

    public function handle(): int
    {
        $enabledValue = AdminSetting::getValue('mcp_enabled', 'false');
        $enabled = in_array($enabledValue, ['true', '1', true], true);

        try {
            if ($enabled) {
                exec('docker compose -f ' . base_path('../docker-compose.yml') . ' up -d mcp 2>&1', $output, $exitCode);
                if ($exitCode === 0) {
                    Log::info('MCP container started successfully');
                } else {
                    Log::error('Failed to start MCP container', ['output' => $output]);
                }
            } else {
                exec('docker compose -f ' . base_path('../docker-compose.yml') . ' down mcp 2>&1', $output, $exitCode);
                if ($exitCode === 0) {
                    Log::info('MCP container stopped successfully');
                } else {
                    Log::error('Failed to stop MCP container', ['output' => $output]);
                }
            }
        } catch (\Exception $e) {
            Log::error('MCP management error', ['error' => $e->getMessage()]);
            return 1;
        }

        return 0;
    }
}
