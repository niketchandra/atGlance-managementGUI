<?php

namespace App\Console\Commands;

use App\Support\McpControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ManageMcpCommand extends Command
{
    protected $signature = 'mcp:manage';

    protected $description = 'Start or stop the MCP container to match the MCP Server plugin (Site Setting > Plugins)';

    public function handle(): int
    {
        $enabled = McpControl::enabled();
        $status = McpControl::status();

        if ($status === McpControl::UNKNOWN) {
            $this->warn('MCP control is not reachable; nothing changed.');

            return 0;
        }

        if (($status === McpControl::RUNNING) === $enabled) {
            return 0;
        }

        $error = McpControl::apply($enabled);
        if ($error !== null) {
            Log::warning('MCP container not ' . ($enabled ? 'started' : 'stopped'), ['error' => $error]);
            $this->error($error);

            return 1;
        }

        $this->info('MCP container ' . ($enabled ? 'started' : 'stopped') . '.');

        return 0;
    }
}
