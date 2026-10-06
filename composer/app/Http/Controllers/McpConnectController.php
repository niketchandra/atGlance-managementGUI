<?php

namespace App\Http\Controllers;

use App\Support\McpConnect;
use App\Support\McpControl;
use Illuminate\Http\Request;
use Illuminate\View\View;

class McpConnectController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(McpControl::enabled(), 404);

        return view('mcp-connect', ['mcp' => McpConnect::info($request->getHost())]);
    }
}
