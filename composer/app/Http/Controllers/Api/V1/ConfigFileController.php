<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Controller;
use App\Models\ConfigurationFile;
use App\Models\SystemRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * /api/v1/config-files for the atglance CLI v2: config backups of the calling system
 * (from the atglance.system middleware). Upload and download reuse FileController.
 */
class ConfigFileController extends Controller
{
    public function __construct(private readonly FileController $files)
    {
    }

    public function store(Request $request)
    {
        $system = $this->system($request);

        // The verified system wins over anything the client sent.
        $request->merge([
            'system_id' => $system->id,
            'system_register_id' => $system->id,
            'validation_hash' => $system->validation_hash,
            'system_hash' => $system->validation_hash,
            'org_id' => $system->org_id,
            'service_id' => null,
        ]);

        $response = $this->files->uploadConfigFile($request);
        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        return response()->json([
            'file' => Arr::only($response->getData(true)['file'] ?? [], ['id', 'file_name', 'service_name', 'version', 'created_at']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['service_name' => ['nullable', 'string', 'max:255']]);

        $files = ConfigurationFile::query()
            ->with(['user:id,name', 'serviceAccount:id,name'])
            ->where('system_register_id', $this->system($request)->id)
            ->where('status', 'active')
            ->when($request->filled('service_name'), fn ($query) => $query->where('service_name', $request->query('service_name')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ConfigurationFile $file) => [
                'id' => $file->id,
                'file_name' => $file->file_name,
                'service_name' => $file->service_name,
                'version' => $file->version,
                'created_at' => $file->created_at,
                'created_by' => $file->user
                    ? ['id' => $file->user->id, 'name' => $file->user->name]
                    : ($file->serviceAccount ? ['service_account' => ['id' => $file->serviceAccount->id, 'name' => $file->serviceAccount->name]] : null),
            ]);

        return response()->json(['files' => $files]);
    }

    public function show(Request $request, int $id)
    {
        return $this->files->downloadSystemConfigFile((int) $this->system($request)->id, $id);
    }

    private function system(Request $request): SystemRegister
    {
        return $request->attributes->get('system');
    }
}
