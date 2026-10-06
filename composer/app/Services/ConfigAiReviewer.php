<?php

namespace App\Services;

use App\Models\ConfigAiValidation;
use App\Notifications\NotificationEvents;
use App\Support\ActivityRecorder;
use App\Support\AiSettings;
use App\Support\S3Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs one AI review of a stored configuration file and saves the result.
 * Used by the "Validate with AI" button and by the automatic checks
 * (ValidateConfigWithAi job: on upload and on the workspace schedule).
 */
class ConfigAiReviewer
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_UPLOAD = 'upload';
    public const TRIGGER_SCHEDULE = 'schedule';

    public function __construct(private readonly ConfigAiValidator $validator)
    {
    }

    /**
     * @throws InvalidArgumentException when the file has no content (nothing was sent to the AI)
     * @throws RuntimeException with a safe message when the AI call fails
     */
    public function review(int $configId, ?int $userId, string $trigger = self::TRIGGER_MANUAL): ConfigAiValidation
    {
        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->where('configuration_files.id', $configId)
            ->select('configuration_files.*', 'raw_data.file_data as data')
            ->first();

        if (!$config) {
            throw new InvalidArgumentException('Configuration file not found.');
        }

        $content = $this->content($config);
        if (trim($content) === '') {
            throw new InvalidArgumentException('This configuration file has no content to validate.');
        }

        $connection = AiSettings::connection();
        $who = $trigger === self::TRIGGER_MANUAL ? '' : ' (automatic, ' . $trigger . ')';

        try {
            $result = $this->validator->validate($connection, (string) $config->file_name, $config->service_name, $content);
        } catch (RuntimeException $e) {
            ActivityRecorder::record($userId, 'config.ai_validated', 'AI validation failed for ' . $config->file_name . $who, ActivityRecorder::FAILURE,
                null, \App\Models\SystemRegister::query()->whereKey($config->system_register_id)->value('workspace_id'), $config->system_register_id ? (int) $config->system_register_id : null);

            throw $e;
        }

        ActivityRecorder::record($userId, 'config.ai_validated', 'Validated ' . $config->file_name . ' with AI (' . $result['status'] . ')' . $who, ActivityRecorder::SUCCESS);

        $validation = ConfigAiValidation::create([
            'configuration_file_id' => $config->id,
            'user_id' => $userId,
            'trigger' => $trigger,
            'provider' => AiSettings::PROVIDERS[$connection['provider']]['label'],
            'model' => (string) $connection['model'],
            'status' => $result['status'],
            'summary' => $result['summary'],
            'result' => $result,
        ]);

        if (in_array($result['status'], ['error', 'warning'], true)) {
            $this->notifyIssues($config, $result);
        }

        return $validation;
    }

    private function notifyIssues(object $config, array $result): void
    {
        $system = DB::table('system_register')->where('id', $config->system_register_id)->first(['workspace_id', 'system_name']);
        if (!$system || (int) $system->workspace_id <= 0) {
            return;
        }

        $counts = collect($result['findings'] ?? [])->countBy('severity');

        app(Notifier::class)->notify(
            NotificationEvents::AI_ISSUES_FOUND,
            (int) $system->workspace_id,
            'AI check found ' . ($result['status'] === 'error' ? 'errors' : 'warnings') . ': ' . $config->file_name,
            array_filter([
                'System' => (string) $system->system_name,
                'Service' => (string) $config->service_name,
                'File' => (string) $config->file_name,
                'Result' => ucfirst($result['status']),
                'Findings' => sprintf('%d error(s), %d warning(s)', $counts['error'] ?? 0, $counts['warning'] ?? 0),
                'Summary' => (string) ($result['summary'] ?? ''),
            ]),
            ['configuration_file_id' => $config->id, 'status' => $result['status'], 'url' => route('configuration-backups.view', ['id' => $config->id])],
        );
    }

    /**
     * The file's content: the stored file when it still exists, else the raw_data copy.
     */
    private function content(object $config): string
    {
        $content = (string) ($config->data ?? '');
        [$disk, $path] = self::diskAndPath($config->storage_disk ?? null, (string) ($config->file_location ?? ''));

        try {
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                $content = (string) Storage::disk($disk)->get($path);
            }
        } catch (\Throwable) {
            // Fall back to the raw_data copy when the disk is unreachable.
        }

        return $content;
    }

    /**
     * Same lookup as the configuration pages: the active disk when the file is there,
     * else the disk recorded with the file (or in a legacy "disk://path" location).
     */
    public static function diskAndPath(?string $storageDisk, string $storedLocation): array
    {
        $explicitDisk = strtolower(trim((string) $storageDisk));
        if ($explicitDisk !== '') {
            $legacy = [$explicitDisk, ltrim($storedLocation, '/')];
        } elseif (preg_match('/^([a-z0-9_-]+):\/\/(.+)$/i', $storedLocation, $matches)) {
            $legacy = [strtolower($matches[1]), $matches[2]];
        } else {
            $legacy = ['local', ltrim($storedLocation, '/')];
        }

        $path = ltrim($legacy[1], '/');
        $activeDisk = S3Settings::activeDisk();

        if ($path === '' || Storage::disk($activeDisk)->exists($path)) {
            return [$activeDisk, $path];
        }

        return [$legacy[0], $path];
    }
}
