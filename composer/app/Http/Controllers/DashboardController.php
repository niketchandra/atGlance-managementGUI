<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\PatToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Show the dashboard view
     */
    public function index()
    {
        $now = Carbon::now();
        $currentWeekStart = $now->copy()->startOfWeek();
        $previousWeekStart = $currentWeekStart->copy()->subWeek();
        $previousWeekEnd = $currentWeekStart->copy()->subSecond();

        $totalConfigBackups = DB::table('configuration_files')->count();
        $currentWeekConfigBackups = DB::table('configuration_files')
            ->whereBetween('created_at', [$currentWeekStart, $now])
            ->count();
        $previousWeekConfigBackups = DB::table('configuration_files')
            ->whereBetween('created_at', [$previousWeekStart, $previousWeekEnd])
            ->count();

        $totalSystemsRegistered = DB::table('system_register')->count();
        $currentWeekSystemsRegistered = DB::table('system_register')
            ->whereBetween('created_at', [$currentWeekStart, $now])
            ->count();
        $previousWeekSystemsRegistered = DB::table('system_register')
            ->whereBetween('created_at', [$previousWeekStart, $previousWeekEnd])
            ->count();

        $configChange = $this->calculateWeeklyChange($currentWeekConfigBackups, $previousWeekConfigBackups);
        $systemsChange = $this->calculateWeeklyChange($currentWeekSystemsRegistered, $previousWeekSystemsRegistered);

        return view('dashboard', [
            'totalConfigBackups' => $totalConfigBackups,
            'totalSystemsRegistered' => $totalSystemsRegistered,
            'configChange' => $configChange,
            'systemsChange' => $systemsChange,
        ]);
    }

    private function calculateWeeklyChange(int $current, int $previous): array
    {
        if ($previous === 0) {
            if ($current === 0) {
                return ['percent' => 0, 'direction' => 'flat'];
            }

            return ['percent' => 100, 'direction' => 'up'];
        }

        $delta = (($current - $previous) / $previous) * 100;

        if ($delta > 0) {
            return ['percent' => (int) round(abs($delta)), 'direction' => 'up'];
        }

        if ($delta < 0) {
            return ['percent' => (int) round(abs($delta)), 'direction' => 'down'];
        }

        return ['percent' => 0, 'direction' => 'flat'];
    }

    public function configurationBackups(Request $request)
    {
        // Subquery to get the latest version for each service
        $latestVersionsSubquery = DB::table('configuration_files')
            ->select('service_name', DB::raw('MAX(id) as latest_id'))
            ->groupBy('service_name');

        $query = DB::table('configuration_files as cf')
            ->joinSub($latestVersionsSubquery, 'latest', function ($join) {
                $join->on('cf.service_name', '=', 'latest.service_name')
                     ->on('cf.id', '=', 'latest.latest_id');
            })
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->select(
                'cf.id',
                'cf.file_name',
                'cf.service_id',
                'cf.service_name',
                'cf.system_register_id',
                'cf.validation_hash',
                'cf.version',
                'cf.status',
                'cf.file_location',
                'cf.created_at',
                'sr.system_name',
                'sr.status as system_status',
                DB::raw('(SELECT COUNT(*) FROM configuration_files WHERE service_name = cf.service_name) as version_count')
            );

        if ($request->filled('service_name')) {
            $query->where('cf.service_name', 'like', '%' . $request->service_name . '%');
        }

        if ($request->filled('status')) {
            $query->where('cf.status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('cf.created_at', $request->date);
        }

        if ($request->filled('system_id')) {
            $query->where('cf.system_register_id', $request->system_id);
        }

        if ($request->filled('hash')) {
            $query->where('cf.validation_hash', 'like', '%' . $request->hash . '%');
        }

        $items = $query->orderByDesc('cf.created_at')
            ->limit(50)
            ->get();

        return view('configuration-backups', compact('items'));
    }

    public function systemsRegistered(Request $request)
    {
        $query = DB::table('system_register');

        // Apply filters
        if ($request->filled('name')) {
            $query->where('system_name', 'like', '%' . $request->name . '%');
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', 'like', '%' . $request->ip . '%');
        }

        if ($request->filled('tags')) {
            $query->where('tags', 'like', '%' . $request->tags . '%');
        }

        if ($request->filled('os')) {
            $query->where('os_type', 'like', '%' . $request->os . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('hash')) {
            $query->where('validation_hash', 'like', '%' . $request->hash . '%');
        }

        $items = $query->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('systems-registered', compact('items'));
    }

    /**
     * List all services for a specific registered system
     */
    public function systemServices(Request $request, $systemId)
    {
        $system = DB::table('system_register')->where('id', $systemId)->first();

        if (!$system) {
            abort(404, 'System not found');
        }

        $query = DB::table('services as s')
            ->leftJoin('configuration_files as cf', 's.service_id', '=', 'cf.service_id')
            ->where('s.system_id', $systemId)
            ->select(
                's.service_id',
                's.service_name',
                's.system_id',
                's.system_hash',
                's.org_id',
                's.share_with',
                's.status',
                's.created_at',
                DB::raw('COUNT(cf.id) as config_count'),
                DB::raw('MAX(cf.version) as latest_version')
            )
            ->groupBy(
                's.service_id',
                's.service_name',
                's.system_id',
                's.system_hash',
                's.org_id',
                's.share_with',
                's.status',
                's.created_at'
            );

        if ($request->filled('service_name')) {
            $query->where('s.service_name', 'like', '%' . $request->service_name . '%');
        }

        if ($request->filled('status')) {
            $query->where('s.status', $request->status);
        }

        $services = $query->orderByDesc('s.created_at')->get();

        return view('system-services', compact('system', 'services'));
    }

    public function liveServiceMonitoring()
    {
        return view('live-service-monitoring');
    }

    public function vulnerabilitiesIdentified()
    {
        return view('vulnerabilities-identified');
    }

    /**
     * View all versions of a service configuration
     */
    public function viewServiceVersions($serviceId)
    {
        $service = DB::table('services')->where('service_id', $serviceId)->first();

        if (!$service) {
            abort(404, 'Service not found');
        }
        
        $versions = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.service_id', $serviceId)
            ->select(
                'cf.id',
                'cf.service_id',
                'cf.file_name',
                'cf.service_name',
                'cf.version',
                'cf.validation_hash',
                'cf.status',
                'cf.created_at',
                'cf.updated_at',
                'sr.system_name',
                'sr.status as system_status'
            )
            ->orderByDesc('cf.created_at')
            ->get();

        if ($versions->isEmpty()) {
            abort(404, 'No configuration files found for this service');
        }

        $serviceName = $service->service_name;
        $systemId = $service->system_id;

        return view('view-service-versions', compact('versions', 'serviceName', 'systemId'));
    }

    /**
     * Backward-compatible versions view by service name
     */
    public function viewServiceVersionsByName($serviceName)
    {
        $serviceName = urldecode($serviceName);

        $versions = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.service_name', $serviceName)
            ->select(
                'cf.id',
                'cf.service_id',
                'cf.file_name',
                'cf.service_name',
                'cf.version',
                'cf.validation_hash',
                'cf.status',
                'cf.created_at',
                'cf.updated_at',
                'sr.system_name',
                'sr.status as system_status',
                'cf.system_register_id'
            )
            ->orderByDesc('cf.created_at')
            ->get();

        if ($versions->isEmpty()) {
            abort(404, 'No configuration files found for this service');
        }

        $systemId = $versions->first()->system_register_id;

        return view('view-service-versions', compact('versions', 'serviceName', 'systemId'));
    }

    /**
     * View configuration file content
     */
    public function viewConfigurationFile($id)
    {
        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'raw_data.file_data as data')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        // Return view with configuration data
        return view('view-configuration', compact('config'));
    }

    /**
     * Download configuration file
     */
    public function downloadConfigurationFile($id)
    {
        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'raw_data.file_data as data')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        // Prepare download content
        $content = $config->data ?? 'No configuration data available';
        $fileName = $config->file_name ?: 'config_' . $id . '.txt';
        
        // Add version suffix if version exists
        if (!empty($config->version)) {
            $fileNameParts = pathinfo($fileName);
            $fileName = $fileNameParts['filename'] . '-' . $config->version . '.' . ($fileNameParts['extension'] ?? 'txt');
        }

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Show the settings view
     */
    public function settings()
    {
        $apiKeys = PatToken::where('user_id', Auth::id())
            ->where('status', 'active')
            ->latest()
            ->get();

        return view('settings', compact('apiKeys'));
    }

    /**
     * Update user settings
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
        ]);

        $updateData = [
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'dob' => $validated['dob'] ?? null,
        ];

        /** @var User $user */
        $user = Auth::user();
        $user->update($updateData);

        return redirect()->back()->with('success', 'Settings updated successfully');
    }

    /**
     * Create a new API key from settings page.
     */
    public function createApiKey(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'expiration_date' => 'nullable|date|after:today',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $plainToken = PatToken::generateCustomToken();
        $expiresAt = $validated['expiration_date'] 
            ? Carbon::parse($validated['expiration_date'])->endOfDay()
            : Carbon::create(2099, 12, 31, 23, 59, 59);

        $token = PatToken::create([
            'user_id' => $user->id,
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => $validated['name'],
            'token' => hash('sha256', $plainToken),
            'token_encrypted' => Crypt::encryptString($plainToken),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'token' => $plainToken,
            'name' => $token->name,
            'key_id' => $token->id,
            'expires_at' => $expiresAt->format('F j, Y'),
        ]);
    }

    /**
     * View a specific API key (requires password confirmation)
     */
    public function viewApiKey(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'key_id' => 'required|integer',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $userPasswordHash = $user->password_hash ?? $user->password;

        // Verify password
        if (!$userPasswordHash || !Hash::check($validated['password'], $userPasswordHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password',
            ], 401);
        }

        $token = PatToken::where('id', $validated['key_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'API key not found',
            ], 404);
        }

        $plainToken = null;
        if (!empty($token->token_encrypted)) {
            try {
                $plainToken = Crypt::decryptString($token->token_encrypted);
            } catch (\Throwable $e) {
                $plainToken = null;
            }
        }

        if ($plainToken === null) {
            return response()->json([
                'success' => false,
                'message' => 'This key was created before secure display was enabled. Please create a new key to view full token value.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'token' => $plainToken,
            'name' => $token->name,
        ]);
    }

    /**
     * Revoke an API key (requires password confirmation)
     */
    public function revokeApiKey(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'key_id' => 'required|integer',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $userPasswordHash = $user->password_hash ?? $user->password;

        // Verify password
        if (!$userPasswordHash || !Hash::check($validated['password'], $userPasswordHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password',
            ], 401);
        }

        $token = PatToken::where('id', $validated['key_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'API key not found',
            ], 404);
        }

        $token->update(['status' => 'revoked']);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
    }

    /**
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->password = Hash::make($validated['password']);
        $user->save();

        return redirect()->back()->with('success', 'Password updated successfully');
    }

    /**
     * Show the profile view
     */
    public function profile()
    {
        return view('profile');
    }

    /**
     * Show the products view
     */
    public function products()
    {
        return view('products');
    }
}
