<?php

namespace CedricKassen\MinecraftModpackModrinth\Jobs;

use App\Models\Server;
use CedricKassen\MinecraftModpackModrinth\Exceptions\ModpackCancelledException;
use CedricKassen\MinecraftModpackModrinth\Exceptions\ModpackException;
use CedricKassen\MinecraftModpackModrinth\Integration\MinecraftModrinthIntegration;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Throwable;
use ZipArchive;

/**
 * Installs, updates or uninstalls the modpack of a server, optionally after creating a backup.
 *
 * Installing a modpack means pulling every file of its index through the daemon one by one,
 * which takes far longer than a web request may run, so this always runs on the queue.
 */
class ModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const OPERATION_INSTALL = 'install';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_UNINSTALL = 'uninstall';

    // Seconds between two looks at a backup that is still being created.
    protected const BACKUP_POLL_INTERVAL = 5;

    // Seconds to wait for a cancelled operation of the same server to wind down.
    protected const LOCK_WAIT_SECONDS = 180;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    protected ?string $tempFile = null;

    /**
     * @param  array{id: string, slug: string, title: string, author?: ?string}  $project
     */
    public function __construct(
        public int $serverId,
        public string $operationId,
        public string $operation,
        public array $project,
        public ?string $versionId,
        public bool $createBackup,
    ) {
        $this->timeout = (int) config('minecraft-modpack-modrinth.job_timeout', 2700);
    }

    public function handle(MinecraftModpackModrinthService $service): void
    {
        $server = Server::find($this->serverId);

        // Cancelled while queued, or a leftover of an operation that was replaced by a newer one.
        if (!$server || $service->isOperationCancelled($server, $this->operationId)) {
            return;
        }

        // A cancelled operation may still be finishing its current file, give it the time to notice.
        $lock = Cache::lock("modrinth_modpack_operation:{$server->id}", $this->timeout + 60);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $this->finish($service, [
                'status' => 'failed',
                'message' => trans('minecraft-modpack-modrinth::strings.errors.already_running'),
            ]);

            return;
        }

        try {
            $this->progress($service, ['status' => 'running', 'step' => 'starting', 'current' => null, 'total' => null]);

            if ($this->createBackup) {
                $this->runBackup($server, $service);
            }

            if ($this->operation === self::OPERATION_UNINSTALL) {
                $this->uninstall($server, $service);
            } else {
                $this->install($server, $service);
            }

            $this->finish($service, ['status' => 'done', 'step' => 'done', 'current' => null, 'total' => null, 'message' => null]);
        } catch (ModpackCancelledException) {
            // The user cancelled, the progress already says so.
        } catch (Throwable $exception) {
            if (!$exception instanceof ModpackException) {
                report($exception);
            }

            $this->finish($service, [
                'status' => 'failed',
                'message' => $exception instanceof ModpackException ? $exception->getMessage() : trans('minecraft-modpack-modrinth::strings.errors.unexpected'),
            ]);
        } finally {
            $this->deleteTempFile();

            $lock->release();
        }
    }

    /**
     * Called when the worker gave up on the job, e.g. because it ran into its timeout.
     */
    public function failed(?Throwable $exception): void
    {
        // A second worker picking the job up after retry_after doesn't mean the first one stopped working on it.
        if ($exception instanceof MaxAttemptsExceededException) {
            return;
        }

        $this->deleteTempFile();

        $this->finish(app(MinecraftModpackModrinthService::class), [
            'status' => 'failed',
            'message' => trans('minecraft-modpack-modrinth::strings.errors.unexpected'),
        ]);
    }

    /**
     * Report progress, stopping the job as soon as the user cancelled the operation.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ModpackCancelledException
     */
    protected function progress(MinecraftModpackModrinthService $service, array $changes = []): void
    {
        if ($service->isOperationCancelled($this->serverId, $this->operationId)) {
            throw new ModpackCancelledException();
        }

        $service->updateProgress($this->serverId, $changes);
    }

    /**
     * Record the outcome, unless the operation was cancelled or replaced in the meantime.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function finish(MinecraftModpackModrinthService $service, array $changes): void
    {
        if (!$service->isOperationCancelled($this->serverId, $this->operationId)) {
            $service->updateProgress($this->serverId, $changes);
        }
    }

    /**
     * @throws Throwable
     */
    protected function runBackup(Server $server, MinecraftModpackModrinthService $service): void
    {
        $this->progress($service, ['step' => 'backing_up']);

        if ($server->backup_limit <= 0 || $service->isBackupLimitReached($server)) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.backup_limit_reached'));
        }

        $name = trans('minecraft-modpack-modrinth::strings.backup.name', [
            'operation' => trans('minecraft-modpack-modrinth::strings.operations.'.$this->operation),
            'title' => $this->project['title'],
        ]);

        try {
            $backup = $service->createBackup($server, str($name)->limit(185)->toString());
        } catch (Throwable $exception) {
            report($exception);

            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.backup_failed'));
        }

        $deadline = now()->addSeconds((int) config('minecraft-modpack-modrinth.backup_timeout', 900));

        // The daemon creates the backup in the background and reports back to the panel once it is done.
        // Nothing gets touched before it has finished successfully.
        while (true) {
            $backup = $backup->fresh();

            if (!$backup) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.backup_failed'));
            }

            if ($backup->completed_at !== null) {
                if (!$backup->is_successful) {
                    throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.backup_failed'));
                }

                return;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.backup_timeout'));
            }

            $this->progress($service);

            sleep(self::BACKUP_POLL_INTERVAL);
        }
    }

    /**
     * @throws Throwable
     */
    protected function install(Server $server, MinecraftModpackModrinthService $service): void
    {
        $this->progress($service, ['step' => 'downloading_pack']);

        $version = $this->versionId ? $service->getVersion($this->versionId) : null;

        if (!$version || ($version['project_id'] ?? null) !== $this->project['id']) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.version_not_found'));
        }

        $mrpack = $service->getMrpackFile(is_array($version['files'] ?? null) ? $version['files'] : []);

        if (!$mrpack) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.no_mrpack'));
        }

        if (($mrpack['size'] ?? 0) > $service->getMaxMrpackBytes()) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.mrpack_too_large'));
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'mrpack_');

        if ($tempFile === false) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.unexpected'));
        }

        $this->tempFile = $tempFile;

        $service->downloadMrpack($mrpack['url'], $tempFile);

        $zip = new ZipArchive();

        if ($zip->open($tempFile, ZipArchive::RDONLY) !== true) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_mrpack'));
        }

        try {
            $index = json_decode((string) $zip->getFromName('modrinth.index.json'), true);

            if (!is_array($index) || ($index['formatVersion'] ?? null) !== 1 || ($index['game'] ?? null) !== 'minecraft' || !is_array($index['files'] ?? null)) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_mrpack'));
            }

            $files = $this->collectIndexFiles($index['files'], $service);
            $overrides = $this->collectOverrides($zip, $service);

            $previous = $service->getInstalledModpack($server);

            $this->downloadIndexFiles($server, $service, $files, $previous);
            $overrideHashes = $this->writeOverrides($server, $service, $zip, $overrides);
        } finally {
            $zip->close();
        }

        $newFiles = array_values(array_unique(array_merge(array_keys($files), array_keys($overrides))));
        sort($newFiles);

        // Whatever the previous pack (an older version or another pack entirely) placed and this one
        // doesn't ship anymore would otherwise linger, e.g. a mod that got dropped from the pack.
        if ($previous) {
            $stale = array_values(array_diff($previous['files'], $newFiles));

            if (!empty($stale)) {
                $this->progress($service, ['step' => 'cleaning_up', 'current' => null, 'total' => count($stale)]);

                $service->deleteFiles($server, $stale);
            }
        }

        $hashes = [];
        foreach ($files as $path => $file) {
            // Overrides win over index files of the same path, so their hash says nothing about what is on disk.
            if ($file['sha1'] !== null && !isset($overrides[$path])) {
                $hashes[$path] = $file['sha1'];
            }
        }

        $isSameProject = $previous && $previous['project_id'] === $this->project['id'];

        $metadata = [
            'project_id' => $this->project['id'],
            'project_slug' => $this->project['slug'],
            'project_title' => $this->project['title'],
            'version_id' => (string) $version['id'],
            'version_number' => (string) ($version['version_number'] ?? $index['versionId'] ?? ''),
            'installed_at' => $isSameProject ? $previous['installed_at'] : now()->toIso8601String(),
            'files' => $newFiles,
            'hashes' => $hashes,
            'dependencies' => is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [],
        ];

        if ($isSameProject) {
            $metadata['updated_at'] = now()->toIso8601String();
        }

        if (!empty($this->project['author'])) {
            $metadata['author'] = $this->project['author'];
        }

        if (!$service->saveModpackMetadata($server, $metadata)) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.metadata_failed'));
        }

        $fileHashes = array_filter(array_map(fn (array $file) => $file['sha1'], $files));

        $this->syncModrinthMods($server, $service, $this->project['id'], array_merge($fileHashes, $overrideHashes), [$previous['project_id'] ?? null, $this->project['id']]);
    }

    /**
     * Show the mods of the pack in the installed tab of the minecraft-modrinth plugin. The pack itself
     * is fine without it, so a failure here doesn't fail the operation.
     *
     * @param  array<string, string>  $files
     * @param  array<int, string|null>  $replaceModpacks
     */
    protected function syncModrinthMods(Server $server, MinecraftModpackModrinthService $service, ?string $modpackProjectId, array $files, array $replaceModpacks): void
    {
        if (!MinecraftModrinthIntegration::isModrinthPluginInstalled()) {
            return;
        }

        try {
            $this->progress($service, ['step' => 'registering_mods', 'current' => null, 'total' => null]);

            $service->syncModrinthModsMetadata($server, $modpackProjectId, $files, array_values(array_filter($replaceModpacks)));
        } catch (ModpackCancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<int, mixed>  $indexFiles
     * @return array<string, array{url: string, sha1: ?string, size: ?int}>
     *
     * @throws ModpackException
     */
    protected function collectIndexFiles(array $indexFiles, MinecraftModpackModrinthService $service): array
    {
        $files = [];

        foreach ($indexFiles as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_mrpack'));
            }

            // Client-only files like shader packs have no business on a server.
            if (($file['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = $service->validateRelativePath($file['path']);

            $url = collect(is_array($file['downloads'] ?? null) ? $file['downloads'] : [])
                ->first(fn ($download) => is_string($download) && $service->isAllowedDownloadUrl($download));

            if (!$url) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.no_valid_download', ['path' => $path]));
            }

            $files[$path] = [
                'url' => $url,
                'sha1' => is_string($file['hashes']['sha1'] ?? null) ? strtolower($file['hashes']['sha1']) : null,
                'size' => is_int($file['fileSize'] ?? null) ? $file['fileSize'] : null,
            ];
        }

        return $files;
    }

    /**
     * Map every override path to its zip entry. Server overrides are collected last so they win.
     *
     * @return array<string, int>
     *
     * @throws ModpackException
     */
    protected function collectOverrides(ZipArchive $zip, MinecraftModpackModrinthService $service): array
    {
        $overrides = [];

        foreach (['overrides/', 'server-overrides/'] as $prefix) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false || str_ends_with($name, '/') || !str_starts_with($name, $prefix)) {
                    continue;
                }

                $relative = substr($name, strlen($prefix));

                if ($relative === '') {
                    continue;
                }

                $overrides[$service->validateRelativePath($relative)] = $i;
            }
        }

        return $overrides;
    }

    /**
     * @param  array<string, array{url: string, sha1: ?string, size: ?int}>  $files
     * @param  array<string, mixed>|null  $previous
     *
     * @throws Throwable
     */
    protected function downloadIndexFiles(Server $server, MinecraftModpackModrinthService $service, array $files, ?array $previous): void
    {
        $previousHashes = $previous['hashes'] ?? [];
        $listings = [];

        $total = count($files);
        $current = 0;

        foreach ($files as $path => $file) {
            $current++;

            $this->progress($service, ['step' => 'installing_files', 'current' => $current, 'total' => $total]);

            $folder = dirname($path);
            $folder = $folder === '.' ? '' : $folder;
            $filename = basename($path);

            // An update usually only changes a handful of mods, so skip whatever is already there unchanged.
            if ($file['sha1'] !== null && ($previousHashes[$path] ?? null) === $file['sha1']) {
                $listings[$folder] ??= $service->listFolder($server, $folder);

                $existing = collect($listings[$folder])->first(fn ($entry) => is_array($entry) && ($entry['name'] ?? null) === $filename);

                if ($existing && ($file['size'] === null || (int) ($existing['size'] ?? -1) === $file['size'])) {
                    continue;
                }
            }

            try {
                $downloaded = $service->downloadFile($server, $file['url'], $folder, $filename);
            } catch (Throwable $exception) {
                report($exception);

                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.download_failed', [
                    'path' => $path,
                    'reason' => $service->describeDaemonError($exception),
                ]));
            }

            if ($file['size'] !== null && isset($downloaded['size']) && (int) $downloaded['size'] !== $file['size']) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.size_mismatch', ['path' => $path]));
            }
        }
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, string> sha1 hash of every written file, by path
     *
     * @throws Throwable
     */
    protected function writeOverrides(Server $server, MinecraftModpackModrinthService $service, ZipArchive $zip, array $overrides): array
    {
        $hashes = [];
        $total = count($overrides);
        $current = 0;

        foreach ($overrides as $path => $index) {
            $current++;

            $this->progress($service, ['step' => 'applying_overrides', 'current' => $current, 'total' => $total]);

            $contents = $zip->getFromIndex($index);

            if ($contents === false) {
                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_mrpack'));
            }

            try {
                $service->putFile($server, $path, $contents);
            } catch (Throwable $exception) {
                report($exception);

                throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.write_failed', [
                    'path' => $path,
                    'size' => strlen($contents),
                    'reason' => $service->describeDaemonError($exception),
                ]));
            }

            $hashes[$path] = sha1($contents);
        }

        return $hashes;
    }

    /**
     * @throws Throwable
     */
    protected function uninstall(Server $server, MinecraftModpackModrinthService $service): void
    {
        $installed = $service->getInstalledModpack($server);

        if (!$installed || $installed['project_id'] !== $this->project['id']) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.not_installed'));
        }

        $chunks = array_chunk($installed['files'], 100);
        $total = count($installed['files']);
        $deleted = 0;

        foreach ($chunks as $chunk) {
            $this->progress($service, ['step' => 'removing_files', 'current' => $deleted, 'total' => $total]);

            $service->deleteFiles($server, $chunk);

            $deleted += count($chunk);
        }

        if (!$service->removeModpackMetadata($server)) {
            // The files are gone but the pack still shows up as installed.
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.uninstall_partial', ['name' => $installed['project_title']]));
        }

        $this->syncModrinthMods($server, $service, null, [], [$installed['project_id']]);
    }

    protected function deleteTempFile(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }

        $this->tempFile = null;
    }
}
