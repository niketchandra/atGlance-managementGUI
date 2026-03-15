<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Service::where('user_id', $user->id)
            ->where('status', 'active');

        if ($request->filled('system_id')) {
            $request->validate([
                'system_id' => ['integer', 'exists:system_register,id'],
            ]);

            $query->where('system_id', $request->input('system_id'));
        }

        $services = $query
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'total' => $services->count(),
            'services' => $services,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'service_name' => ['required', 'string', 'max:255'],
            'system_id' => ['required', 'integer', 'exists:system_register,id'],
            'system_hash' => ['nullable', 'string', 'max:255'],
            'org_id' => ['nullable', 'integer'],
            'share_with' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        $service = Service::firstOrCreate(
            [
                'user_id' => $user->id,
                'system_id' => $data['system_id'],
                'service_name' => $data['service_name'],
            ],
            [
                'system_hash' => $data['system_hash'] ?? null,
                'org_id' => $data['org_id'] ?? $user->org_id,
                'share_with' => $data['share_with'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]
        );

        return response()->json([
            'message' => 'Service saved successfully',
            'service' => $service,
        ], 201);
    }

    public function show(Request $request, $serviceId)
    {
        $user = $request->user();

        $service = Service::where('service_id', $serviceId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found',
            ], 404);
        }

        return response()->json([
            'service' => $service,
        ]);
    }
}
