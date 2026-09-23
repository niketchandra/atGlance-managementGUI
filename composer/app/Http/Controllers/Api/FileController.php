<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationFile;
use App\Models\RawData;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    /**
     * Upload configuration file (text file with .config extension or no extension)
     * Saves to file system and stores raw data in database
     */
    public function uploadConfigFile(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240', // Max 10MB
                function ($attribute, $value, $fail) {
                    $extension = $value->getClientOriginalExtension();
                    $mimeType = $value->getMimeType();
                    
                    // Allow .config files, files without extension, and text files
                    $allowedExtensions = ['config', 'conf', 'cfg', 'txt', ''];
                    $allowedMimeTypes = [
                        'text/plain',
                        'application/octet-stream',
                        'application/x-config',
                        'text/x-config',
                    ];
                    
                    if (!in_array($extension, $allowedExtensions) && !in_array($mimeType, $allowedMimeTypes)) {
                        $fail('The file must be a text/configuration file.');
                    }
                },
            ],
            'system_register_id' => 'nullable|integer|exists:system_register,id',
            'system_id' => 'nullable|integer|exists:system_register,id',
            'service_id' => 'nullable|integer|exists:services,service_id',
            'service_name' => 'nullable|string|max:255',
            'system_hash' => 'nullable|string|max:255',
            'org_id' => 'nullable|integer',
            'share_with' => 'nullable|string|max:255',
            'validation_hash' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $file = $request->file('file');

        $systemId = $request->input('system_register_id', $request->input('system_id'));

        if (!$request->filled('service_id') && (!$systemId || !$request->filled('service_name'))) {
            return response()->json([
                'message' => 'service_name and system_id (or system_register_id) are required when service_id is not provided.',
            ], 422);
        }
        
        // Get original filename
        $originalName = $file->getClientOriginalName();
        
        // Generate unique filename with UUID
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . ($extension ? '.' . $extension : '');
        
        // Define storage path: config_files/{user_id}/
        $storagePath = "config_files/{$user->id}";

        $storageDisk = $this->resolveStorageDisk();

        // Store file in configured disk
        $filePath = $file->storeAs($storagePath, $fileName, $storageDisk);

        // Read file content for raw_data table
        $fileContent = Storage::disk($storageDisk)->get($filePath);

        $service = null;

        if ($request->filled('service_id')) {
            $service = Service::where('service_id', $request->input('service_id'))
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (!$service) {
                return response()->json([
                    'message' => 'service_id not found or does not belong to you.',
                ], 404);
            }

            $systemId = $service->system_id;
        }

        if (!$service && $systemId && $request->filled('service_name')) {
            $service = Service::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'system_id' => $systemId,
                    'service_name' => $request->input('service_name'),
                ],
                [
                    'system_hash' => $request->input('system_hash', $request->input('validation_hash')),
                    'org_id' => $request->input('org_id'),
                    'share_with' => $request->input('share_with'),
                    'status' => 'active',
                ]
            );
        }

        if (!$service) {
            return response()->json([
                'message' => 'Unable to resolve service for upload.',
            ], 422);
        }

        $serviceName = $service->service_name;

        if ($request->filled('validation_hash') && empty($service->system_hash)) {
            $service->system_hash = $request->input('validation_hash');
            $service->save();
        }

        $resolvedVersion = $this->resolveNextVersionLabel((int) $service->service_id);
        
        $configFile = DB::transaction(function () use ($user, $systemId, $service, $originalName, $serviceName, $filePath, $storageDisk, $request, $fileContent, $resolvedVersion) {
            $payload = [
                'user_id' => $user->id,
                'system_register_id' => $systemId,
                'service_id' => $service->service_id,
                'file_name' => $originalName,
                'service_name' => $serviceName,
                'file_location' => $filePath,
                'validation_hash' => $request->input('validation_hash'),
                'version' => $resolvedVersion,
            ];

            if ($this->hasStorageDiskColumn()) {
                $payload['storage_disk'] = $storageDisk;
            }

            $configFile = ConfigurationFile::create($payload);

            RawData::create([
                'file_id' => $configFile->id,
                'user_id' => $user->id,
                'system_register_id' => $systemId,
                'service_id' => $service->service_id,
                'file_name' => $originalName,
                'service_name' => $serviceName,
                'file_data' => $fileContent,
                'validation_hash' => $request->input('validation_hash'),
                'version' => $resolvedVersion,
            ]);

            return $configFile;
        });

        $fileMetadata = $this->resolveFileLocationMetadata($configFile->storage_disk ?? null, (string) $configFile->file_location);

        return response()->json([
            'message' => 'Configuration file uploaded successfully',
            'file' => [
                'id' => $configFile->id,
                'file_name' => $configFile->file_name,
                'original_name' => $originalName,
                'file_location' => $configFile->file_location,
            'storage_disk' => $fileMetadata['disk'],
            'file_relative_path' => $fileMetadata['path'],
            'storage_base_url' => $this->resolveStorageBaseUrl($fileMetadata['disk']),
            'file_url' => $this->buildFileUrl($fileMetadata['disk'], $fileMetadata['path']),
                'file_size' => strlen($fileContent),
                'system_register_id' => $configFile->system_register_id,
                'service_id' => $configFile->service_id,
                'service_name' => $configFile->service_name,
                'validation_hash' => $configFile->validation_hash,
                'version' => $configFile->version,
                'created_at' => $configFile->created_at,
            ],
        ], 201);
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'file_data' => ['required', 'string'], // base64 or raw text
        ]);

        $user = $request->user();

        // Generate unique file location (relative path only)
        $fileLocation = 'uploads/' . $user->id . '/' . Str::uuid() . '_' . $data['file_name'];

        $payload = [
            'user_id' => $user->id,
            'file_name' => $data['file_name'],
            'file_location' => $fileLocation,
        ];

        if ($this->hasStorageDiskColumn()) {
            $payload['storage_disk'] = 'local';
        }

        // Create configuration file record
        $configFile = ConfigurationFile::create($payload);

        $fileMetadata = $this->resolveFileLocationMetadata($configFile->storage_disk ?? null, (string) $configFile->file_location);

        // Store raw data
        RawData::create([
            'file_id' => $configFile->id,
            'user_id' => $user->id,
            'file_name' => $data['file_name'],
            'file_data' => $data['file_data'],
        ]);

        return response()->json([
            'message' => 'File uploaded successfully',
            'file' => [
                'id' => $configFile->id,
                'file_name' => $configFile->file_name,
                'file_location' => $configFile->file_location,
                'storage_disk' => $fileMetadata['disk'],
                'file_relative_path' => $fileMetadata['path'],
                'storage_base_url' => $this->resolveStorageBaseUrl($fileMetadata['disk']),
                'file_url' => $this->buildFileUrl($fileMetadata['disk'], $fileMetadata['path']),
                'created_at' => $configFile->created_at,
            ],
        ], 201);
    }

    public function download(Request $request, $fileId)
    {
        $user = $request->user();

        // Find the configuration file
        $configFile = ConfigurationFile::where('id', $fileId)
            ->where('user_id', $user->id)
            ->with('rawData')
            ->firstOrFail();

        return response()->json([
            'file' => [
                'id' => $configFile->id,
                'file_name' => $configFile->file_name,
                'file_location' => $configFile->file_location,
                'storage_disk' => $this->resolveFileLocationMetadata($configFile->storage_disk ?? null, (string) $configFile->file_location)['disk'],
                'file_data' => $configFile->rawData->file_data,
                'created_at' => $configFile->created_at,
                'updated_at' => $configFile->updated_at,
            ],
        ]);
    }

    /**
     * List all configuration files for the authenticated user (active only)
     */
    public function listConfigFiles(Request $request)
    {
        $user = $request->user();

        $files = ConfigurationFile::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($file) {
                $location = $this->resolveFileLocationMetadata($file->storage_disk ?? null, (string) $file->file_location);

                return [
                    'id' => $file->id,
                    'file_name' => $file->file_name,
                    'service_id' => $file->service_id,
                    'service_name' => $file->service_name,
                    'system_register_id' => $file->system_register_id,
                    'validation_hash' => $file->validation_hash,
                    'version' => $file->version,
                    'file_location' => $file->file_location,
                    'storage_disk' => $location['disk'],
                    'file_relative_path' => $location['path'],
                    'storage_base_url' => $this->resolveStorageBaseUrl($location['disk']),
                    'file_url' => $this->buildFileUrl($location['disk'], $location['path']),
                    'status' => $file->status,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                ];
            });

        return response()->json([
            'total' => $files->count(),
            'files' => $files,
        ]);
    }

    /**
     * List configuration files filtered by system_id and validation_hash (active only)
     */
    public function listConfigFilesBySystemAndHash(Request $request)
    {
        $data = $request->validate([
            'system_id' => ['required', 'integer', 'exists:system_register,id'],
            'validation_hash' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $files = ConfigurationFile::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('system_register_id', $data['system_id'])
            ->where('validation_hash', $data['validation_hash'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($file) {
                $location = $this->resolveFileLocationMetadata($file->storage_disk ?? null, (string) $file->file_location);

                return [
                    'id' => $file->id,
                    'file_name' => $file->file_name,
                    'service_id' => $file->service_id,
                    'service_name' => $file->service_name,
                    'system_register_id' => $file->system_register_id,
                    'validation_hash' => $file->validation_hash,
                    'version' => $file->version,
                    'file_location' => $file->file_location,
                    'storage_disk' => $location['disk'],
                    'file_relative_path' => $location['path'],
                    'storage_base_url' => $this->resolveStorageBaseUrl($location['disk']),
                    'file_url' => $this->buildFileUrl($location['disk'], $location['path']),
                    'status' => $file->status,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                ];
            });

        return response()->json([
            'system_id' => (int) $data['system_id'],
            'validation_hash' => $data['validation_hash'],
            'total' => $files->count(),
            'files' => $files,
        ]);
    }

    /**
     * List all versions by system_id, validation_key, and service_name across all users.
     */
    public function listConfigVersionsBySystemValidationAndService(Request $request)
    {
        $data = $request->validate([
            'system_id' => ['required', 'integer', 'exists:system_register,id'],
            'validation_key' => ['required', 'string', 'max:255'],
            'service_name' => ['required', 'string', 'max:255'],
        ]);

        $files = ConfigurationFile::query()
            ->leftJoin('users', 'users.id', '=', 'configuration_files.user_id')
            ->where('configuration_files.system_register_id', $data['system_id'])
            ->where('configuration_files.validation_hash', $data['validation_key'])
            ->where('configuration_files.service_name', $data['service_name'])
            ->where('configuration_files.status', 'active')
            ->orderByDesc('configuration_files.created_at')
            ->select([
                'configuration_files.id',
                'configuration_files.file_name',
                'configuration_files.service_id',
                'configuration_files.service_name',
                'configuration_files.system_register_id',
                'configuration_files.validation_hash',
                'configuration_files.version',
                'configuration_files.file_location',
                'configuration_files.storage_disk',
                'configuration_files.status',
                'configuration_files.created_at',
                'configuration_files.updated_at',
                'configuration_files.user_id as created_by_user_id',
                'users.name as created_by_user_name',
                'users.email as created_by_user_email',
            ])
            ->get()
            ->map(function ($file) {
                $location = $this->resolveFileLocationMetadata($file->storage_disk ?? null, (string) $file->file_location);

                return [
                    'id' => $file->id,
                    'file_name' => $file->file_name,
                    'service_id' => $file->service_id,
                    'service_name' => $file->service_name,
                    'system_register_id' => $file->system_register_id,
                    'validation_key' => $file->validation_hash,
                    'version' => $file->version,
                    'file_location' => $file->file_location,
                    'storage_disk' => $location['disk'],
                    'file_relative_path' => $location['path'],
                    'storage_base_url' => $this->resolveStorageBaseUrl($location['disk']),
                    'file_url' => $this->buildFileUrl($location['disk'], $location['path']),
                    'status' => $file->status,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'USER_NAME' => $file->created_by_user_name,
                    'USER_EMAIL' => $file->created_by_user_email,
                    'created_by' => [
                        'id' => $file->created_by_user_id,
                        'name' => $file->created_by_user_name,
                        'email' => $file->created_by_user_email,
                    ],
                ];
            });

        return response()->json([
            'system_id' => (int) $data['system_id'],
            'validation_key' => $data['validation_key'],
            'service_name' => $data['service_name'],
            'total' => $files->count(),
            'versions' => $files,
        ]);
    }

    /**
     * Download configuration file from file system (active only)
     */
    public function downloadConfigFile(Request $request, $fileId)
    {
        $user = $request->user();

        // Find the configuration file (active only)
        $configFile = ConfigurationFile::where('id', $fileId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->firstOrFail();

        // Check if file exists in storage
        [$disk, $path] = $this->resolveDiskAndPath($configFile->storage_disk ?? null, (string) $configFile->file_location);

        if (!Storage::disk($disk)->exists($path)) {
            return response()->json([
                'message' => 'File not found in storage',
            ], 404);
        }

        // Get file content
        $fileContent = Storage::disk($disk)->get($path);
        $mimeType = 'application/octet-stream';

        // Return file as download
        return response($fileContent, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'attachment; filename="' . $configFile->file_name . '"');
    }

    /**
     * Download configuration file by configuration file ID (active only)
     */
    public function downloadConfigFileById(Request $request, $id)
    {
        $data = $request->validate([
            'system_id' => ['required', 'integer', 'exists:system_register,id'],
        ]);

        $user = $request->user();

        $configFile = ConfigurationFile::where('id', $id)
            ->where('user_id', $user->id)
            ->where('system_register_id', $data['system_id'])
            ->where('status', 'active')
            ->first();

        if (!$configFile) {
            return response()->json([
                'message' => 'Configuration file not found for provided id and system_id',
            ], 404);
        }

        [$disk, $path] = $this->resolveDiskAndPath($configFile->storage_disk ?? null, (string) $configFile->file_location);

        if (!Storage::disk($disk)->exists($path)) {
            return response()->json([
                'message' => 'File not found in storage',
            ], 404);
        }

        $fileContent = Storage::disk($disk)->get($path);
        $mimeType = 'application/octet-stream';

        return response($fileContent, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'attachment; filename="' . $configFile->file_name . '"');
    }

    /**
     * Get raw data for a specific configuration file (active only)
     */
    public function getRawData(Request $request, $fileId)
    {
        $user = $request->user();

        // Find the configuration file with raw data (active only)
        $configFile = ConfigurationFile::where('id', $fileId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['rawData' => function ($query) {
                $query->where('status', 'active');
            }])
            ->firstOrFail();

        if (!$configFile->rawData) {
            return response()->json([
                'message' => 'Raw data not found for this file',
            ], 404);
        }

        return response()->json([
            'file_id' => $configFile->id,
            'file_name' => $configFile->file_name,
            'service_id' => $configFile->service_id,
            'service_name' => $configFile->service_name,
            'system_register_id' => $configFile->system_register_id,
            'raw_data' => [
                'id' => $configFile->rawData->id,
                'file_data' => $configFile->rawData->file_data,
                'version' => $configFile->rawData->version,
                'status' => $configFile->rawData->status,
                'created_at' => $configFile->rawData->created_at,
                'updated_at' => $configFile->rawData->updated_at,
            ],
        ]);
    }

    /**
     * Soft delete configuration file (mark as inactive)
     * Also marks the related raw_data as inactive
     */
    public function deleteConfigFile(Request $request, $fileId)
    {
        $user = $request->user();

        // Find the configuration file (active only)
        $configFile = ConfigurationFile::where('id', $fileId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('rawData')
            ->firstOrFail();

        // Mark configuration file as inactive
        $configFile->status = 'inactive';
        $configFile->touch(); // Updates updated_at timestamp
        $configFile->save();

        // Mark raw data as inactive
        if ($configFile->rawData) {
            $configFile->rawData->status = 'inactive';
            $configFile->rawData->touch(); // Updates updated_at timestamp
            $configFile->rawData->save();
        }

        return response()->json([
            'message' => 'Configuration file marked as inactive successfully',
            'file' => [
                'id' => $configFile->id,
                'file_name' => $configFile->file_name,
                'status' => $configFile->status,
                'updated_at' => $configFile->updated_at,
            ],
        ]);
    }

    private function resolveStorageDisk(): string
    {
        $s3Enabled = filter_var((string) env('S3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);

        if (!$s3Enabled) {
            return 'local';
        }

        $key = trim((string) config('filesystems.disks.s3.key', env('AWS_ACCESS_KEY_ID', '')));
        $secret = trim((string) config('filesystems.disks.s3.secret', $this->resolveAwsSecretFromEnv()));
        $region = trim((string) config('filesystems.disks.s3.region', env('AWS_DEFAULT_REGION', '')));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET', '')));

        if ($key === '' || $secret === '' || $region === '' || $bucket === '') {
            return 'local';
        }

        Config::set('filesystems.disks.s3.key', $key);
        Config::set('filesystems.disks.s3.secret', $secret);
        Config::set('filesystems.disks.s3.region', $region);
        Config::set('filesystems.disks.s3.bucket', $bucket);

        return 's3';
    }

    private function resolveAwsSecretFromEnv(): string
    {
        $raw = trim((string) env('AWS_SECRET_ACCESS_KEY', ''));
        if ($raw === '') {
            return '';
        }

        if (!str_starts_with($raw, 'ENC:')) {
            return $raw;
        }

        try {
            return trim(Crypt::decryptString(substr($raw, 4)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveDiskAndPath(?string $storageDisk, string $storedLocation): array
    {
        $path = ltrim($storedLocation, '/');
        $activeDisk = $this->resolveStorageDisk();
        $legacy = $this->resolveLegacyDiskAndPath($storageDisk, $storedLocation);

        if ($path === '' && isset($legacy[1])) {
            $path = ltrim((string) $legacy[1], '/');
        }

        if ($path === '') {
            return [$activeDisk, $path];
        }

        if (Storage::disk($activeDisk)->exists($path)) {
            return [$activeDisk, $path];
        }

        return [$legacy[0], ltrim((string) $legacy[1], '/')];
    }

    private function resolveFileLocationMetadata(?string $storageDisk, string $storedLocation): array
    {
        [$disk, $path] = $this->resolveDiskAndPath($storageDisk, $storedLocation);

        return [
            'disk' => $disk,
            'path' => $path,
        ];
    }

    private function resolveStorageBaseUrl(string $disk): string
    {
        $activeDisk = $this->resolveStorageDisk();

        if (strtolower($activeDisk) === 's3') {
            $configured = trim((string) env('S3_STORAGE_BASE_URL', ''));
            if ($configured !== '') {
                return $configured;
            }

            $bucket = trim((string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET', '')));
            $region = trim((string) config('filesystems.disks.s3.region', env('AWS_DEFAULT_REGION', '')));

            if ($bucket !== '' && $region !== '') {
                return sprintf('https://%s.s3.%s.amazonaws.com/', $bucket, $region);
            }

            return '';
        }

        $configured = trim((string) env('LOCAL_STORAGE_BASE_URL', ''));
        if ($configured !== '') {
            return $configured;
        }

        $siteUrl = rtrim((string) config('app.url', ''), '/');

        return $siteUrl !== '' ? $siteUrl . '/storage' : '';
    }

    private function buildFileUrl(string $disk, string $path): string
    {
        $baseUrl = $this->resolveStorageBaseUrl($disk);
        if ($baseUrl === '') {
            return $path;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function hasStorageDiskColumn(): bool
    {
        static $hasColumn;

        if ($hasColumn !== null) {
            return $hasColumn;
        }

        $hasColumn = Schema::hasColumn('configuration_files', 'storage_disk');

        return $hasColumn;
    }

    private function resolveNextVersionLabel(int $serviceId): string
    {
        $existingVersions = ConfigurationFile::query()
            ->where('service_id', $serviceId)
            ->pluck('version')
            ->filter(fn ($version) => is_string($version) && trim($version) !== '')
            ->values();

        $maxVersionNumber = 0;

        foreach ($existingVersions as $versionLabel) {
            $label = strtolower(trim((string) $versionLabel));

            if (preg_match('/^v?(\d+)$/', $label, $matches)) {
                $maxVersionNumber = max($maxVersionNumber, (int) $matches[1]);
            }
        }

        return 'v' . ($maxVersionNumber + 1);
    }

    private function resolveLegacyDiskAndPath(?string $storageDisk, string $storedLocation): array
    {
        $explicitDisk = strtolower(trim((string) $storageDisk));
        if ($explicitDisk !== '') {
            return [$explicitDisk, ltrim($storedLocation, '/')];
        }

        if (preg_match('/^([a-z0-9_-]+):\/\/(.+)$/i', $storedLocation, $matches)) {
            return [strtolower($matches[1]), $matches[2]];
        }

        return ['local', $storedLocation];
    }
}
