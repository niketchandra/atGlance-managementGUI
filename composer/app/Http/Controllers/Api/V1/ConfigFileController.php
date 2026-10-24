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
        // The system stores only a hash of its key; the key itself is the one the host sent,
        // already checked against that hash by the atglance.system middleware. Each upload
        // keeps its own copy, used by the legacy config-files/filter lookup.
        $key = (string) $request->header('X-Atglance-System-Key', '');

        // The verified system wins over anything the client sent.
        $request->merge([
            'system_id' => $system->id,
            'system_register_id' => $system->id,
            'validation_hash' => $key,
            'system_hash' => $key,
            'org_id' => $system->org_id,
            'service_id' => null,
        ]);

        $response = $this->files->uploadConfigFile($request);
        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        return response()->json([
            'file' => Arr::only($response->getData(true)['file'] ?? [], ['id', 'file_name', 'service_name', 'version', 'created_at', 'sha256']),
        ], 201);
    }

    /**
     * Active versions of the calling system, newest first. Optional service_name; with
     * latest=1 only the newest version (highest id) of each service. sha256 is null for
     * versions stored before hashes were kept and not backfilled yet.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'service_name' => ['nullable', 'string', 'max:255'],
            'latest' => ['nullable', 'in:0,1,true,false'],
        ]);

        $systemId = (int) $this->system($request)->id;
        $scoped = fn () => ConfigurationFile::query()
            ->where('system_register_id', $systemId)
            ->where('status', 'active')
            ->when($request->filled('service_name'), fn ($query) => $query->where('service_name', $request->query('service_name')));
        $hasHash = FileController::hasContentHashColumn();

        $files = $scoped()
            ->with(['user:id,name', 'serviceAccount:id,name'])
            ->when($request->boolean('latest'), fn ($query) => $query->whereIn('id', $scoped()->selectRaw('max(id)')->groupBy('service_name')))
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
                'sha256' => $hasHash ? $file->content_sha256 : null,
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
