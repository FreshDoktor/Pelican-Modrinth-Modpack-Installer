<?php

namespace CedricKassen\MinecraftModpackModrinth\Services;

use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use CedricKassen\MinecraftModpackModrinth\Exceptions\ModpackException;
use CedricKassen\MinecraftModpackModrinth\Integration\MinecraftModrinthIntegration;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @phpstan-type InstalledModpack array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, installed_at: string, updated_at?: string, author?: string, files: array<int, string>, hashes: array<string, string>, dependencies?: array<string, string>}
 * @phpstan-type ModpackProgress array{status: string, operation_id?: string, operation?: string, step?: string, current?: ?int, total?: ?int, message?: ?string, title?: ?string, version_number?: ?string, updated_at: int}
 */
class MinecraftModpackModrinthService
{
    public const METADATA_FILE = '.modrinth-modpack.json';

    public const ACTIVE_STATUSES = ['queued', 'running'];

    // Installed list of the minecraft-modrinth plugin, and the key marking entries a modpack added to it.
    public const MODRINTH_MODS_METADATA_FILE = 'mods/.modrinth-metadata.json';

    public const MODPACK_ENTRY_KEY = 'installed_by_modpack';

    // Seconds to wait for the daemon while it downloads a file for us.
    protected const DOWNLOAD_TIMEOUT = 120;

    // Version list cache TTL (minutes); kept short so a transient empty Modrinth response can't hide updates for long.
    protected const VERSIONS_CACHE_MINUTES = 30;

    protected const EMPTY_VERSIONS_CACHE_MINUTES = 5;

    // A queued or running operation that hasn't reported progress for this long is treated as dead,
    // e.g. because no queue worker is running or the worker got killed.
    protected const STALE_PROGRESS_MINUTES = 20;

    protected const DEFAULT_DOWNLOAD_HOSTS = [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ];

    public function getMinecraftVersion(Server $server): ?string
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        // Proxies are not tied to a specific minecraft version
        if (in_array('proxy', $tags)) {
            return null;
        }

        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return $this->getLatestMinecraftVersion();
        }

        return $version;
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth_modpack:latest_minecraft_version', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    public static function isEnabledForServer(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        // Same rule as the mods page of the minecraft-modrinth plugin, modpacks are just a bundle of mods.
        return in_array('modrinth_mods', $features) || (in_array('minecraft', $tags) && in_array('mods', $features));
    }

    /** @return array{icon: string, name: string, supported_project_types: string[], display_name: string}|null */
    public function getLoaderFromServer(Server $server): ?array
    {
        if (!static::isEnabledForServer($server)) {
            return null;
        }

        $tags = $server->egg->tags ?? [];

        if (!in_array('minecraft', $tags)) {
            return null;
        }

        foreach ($this->getLoaders() as $loader) {
            if (!in_array('modpack', $loader['supported_project_types'] ?? [])) {
                continue;
            }

            if (in_array($loader['name'], $tags)) {
                return array_merge($loader, ['display_name' => str($loader['name'])->title()->toString()]);
            }
        }

        return null;
    }

    /** @return array<int, array{icon: string, name: string, supported_project_types: string[]}> */
    public function getLoaders(): array
    {
        return cache()->remember('modrinth_modpack:loaders', now()->addHour(), function () {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/loader')
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $facets = '[["categories:' . $minecraftLoader . '"]';

        if ($minecraftVersion) {
            $facets .= ',["versions:' . $minecraftVersion . '"]';
        }

        $facets .= ',["project_type:modpack"]]';

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => $facets,
        ];

        $key = "modrinth_modpack:projects:$minecraftVersion:$minecraftLoader:$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ':'.md5($search);
        }

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/search', $data)
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not caching on purpose since caching a failure would keep the page empty for 30 minutes
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        cache()->put($key, $response, now()->addMinutes(30));

        return $response;
    }

    /**
     * Build the table row for the installed modpack, falling back to the stored metadata when
     * the project is gone from Modrinth.
     *
     * @param  InstalledModpack  $installedModpack
     * @return array<string, mixed>
     */
    public function getInstalledModpackFromModrinth(array $installedModpack): array
    {
        $projectId = $installedModpack['project_id'];
        $cacheKey = "modrinth_modpack:project:$projectId";

        $project = cache()->get($cacheKey);

        if (!is_array($project)) {
            try {
                $project = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/project/$projectId")
                    ->json();

                if (is_array($project)) {
                    cache()->put($cacheKey, $project, now()->addMinutes(30));
                }
            } catch (Exception $exception) {
                // A 404 just means the project was removed, everything else is worth knowing about.
                if (!$exception instanceof RequestException || $exception->response->status() !== 404) {
                    report($exception);
                }

                $project = null;
            }
        }

        if (is_array($project) && isset($project['id'])) {
            $project['project_id'] = $project['id'];
            $project['date_modified'] ??= $project['updated'] ?? null;
            $project['author'] ??= $installedModpack['author'] ?? '';

            return $project;
        }

        return [
            'project_id' => $installedModpack['project_id'],
            'slug' => $installedModpack['project_slug'],
            'title' => $installedModpack['project_title'],
            'description' => trans('minecraft-modpack-modrinth::strings.page.modpack_unavailable'),
            'icon_url' => null,
            'author' => $installedModpack['author'] ?? '',
            'downloads' => 0,
            'date_modified' => $installedModpack['installed_at'],
            'project_type' => 'modpack',
            'unavailable' => true,
        ];
    }

    protected function getVersionsCacheKey(string $projectId, ?string $minecraftVersion, string $minecraftLoader): string
    {
        return "modrinth_modpack:versions:$projectId:$minecraftVersion:$minecraftLoader";
    }

    /** @return array<array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_versions: string[], version_type: string, loaders: string[], featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}> */
    public function getProjectVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $key = $this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader);

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $query = [
            'loaders' => '["' . $minecraftLoader . '"]',
        ];

        if ($minecraftVersion) {
            $query['game_versions'] = '["' . $minecraftVersion . '"]';
        }

        try {
            $versions = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get("https://api.modrinth.com/v2/project/$projectId/version", $query)
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not cached on purpose since an empty list would hide the update action for 30 minutes.
            return [];
        }

        $versions = is_array($versions) ? $versions : [];
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        cache()->put($key, $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));

        return $versions;
    }

    /**
     * The compatible versions of several projects at once, fetched in parallel. Shares its cache
     * with getProjectVersions().
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>> versions by project id
     */
    public function getProjectVersionsBulk(array $projectIds, Server $server): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, 'is_string')));

        $minecraftLoader = $this->getLoaderFromServer($server);

        if (empty($projectIds) || !$minecraftLoader) {
            return array_fill_keys($projectIds, []);
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $results = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader));

            if (is_array($cached)) {
                $results[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $query = ['loaders' => '["' . $minecraftLoader . '"]'];

        if ($minecraftVersion) {
            $query['game_versions'] = '["' . $minecraftVersion . '"]';
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $projectId) => $pool->as($projectId)
                    ->asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version', $query),
                $missing
            ));
        } catch (Exception $exception) {
            report($exception);

            $responses = [];
        }

        foreach ($missing as $projectId) {
            $response = $responses[$projectId] ?? null;

            // Not cached on purpose, an empty list would hide the update action for a while.
            if (!$response instanceof Response || !$response->successful()) {
                $results[$projectId] = [];

                continue;
            }

            $versions = $response->json();
            $versions = is_array($versions) ? $versions : [];
            usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

            cache()->put($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader), $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));

            $results[$projectId] = $versions;
        }

        return $results;
    }

    /**
     * Fetch a single version, uncached, so a job always works with what Modrinth has right now.
     *
     * @return array<string, mixed>|null
     */
    public function getVersion(string $versionId): ?array
    {
        try {
            $version = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/version/'.rawurlencode($versionId))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        return is_array($version) ? $version : null;
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array{url: string, filename: string, size?: int, primary?: bool}|null
     */
    public function getMrpackFile(array $files): ?array
    {
        $mrpacks = array_values(array_filter($files, fn ($file) => is_array($file) && isset($file['url'], $file['filename']) && str_ends_with(strtolower($file['filename']), '.mrpack')));

        foreach ($mrpacks as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return $mrpacks[0] ?? null;
    }

    /**
     * Download an .mrpack to the panel, aborting as soon as it grows past the configured limit.
     *
     * @throws Exception
     */
    public function downloadMrpack(string $url, string $destination): void
    {
        if (!$this->isAllowedDownloadUrl($url)) {
            throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_download_url'));
        }

        $maxBytes = $this->getMaxMrpackBytes();

        Http::timeout(600)
            ->connectTimeout(10)
            ->withOptions([
                'progress' => function ($downloadTotal, $downloadedBytes) use ($maxBytes) {
                    if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                        throw new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.mrpack_too_large'));
                    }
                },
            ])
            ->sink($destination)
            ->throw()
            ->get($url);
    }

    public function getMaxMrpackBytes(): int
    {
        return (int) config('minecraft-modpack-modrinth.max_mrpack_size_mb', 500) * 1024 * 1024;
    }

    public function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $hosts = config('minecraft-modpack-modrinth.allowed_download_hosts', self::DEFAULT_DOWNLOAD_HOSTS);

        return in_array(strtolower($parts['host']), array_map('strtolower', is_array($hosts) ? $hosts : self::DEFAULT_DOWNLOAD_HOSTS), true);
    }

    /**
     * Normalise a path from a modpack and make sure it stays inside the server's root.
     *
     * @throws ModpackException
     */
    public function validateRelativePath(string $path): string
    {
        $invalid = new ModpackException(trans('minecraft-modpack-modrinth::strings.errors.invalid_path', ['path' => $path]));

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw $invalid;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw $invalid;
            }

            $segments[] = $segment;
        }

        if (empty($segments)) {
            throw $invalid;
        }

        $normalised = implode('/', $segments);

        // The pack must not be able to overwrite our own bookkeeping.
        if (strcasecmp($normalised, self::METADATA_FILE) === 0) {
            throw $invalid;
        }

        return $normalised;
    }

    /** @return InstalledModpack|null */
    public function getInstalledModpack(Server $server): ?array
    {
        try {
            $content = app(DaemonFileRepository::class)->setServer($server)->getContent(self::METADATA_FILE);
            $metadata = json_decode($content, true);
        } catch (FileNotFoundException) {
            return null;
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        if (!is_array($metadata)) {
            return null;
        }

        foreach (['project_id', 'project_slug', 'project_title', 'version_id', 'version_number', 'installed_at'] as $key) {
            if (!isset($metadata[$key]) || !is_string($metadata[$key])) {
                return null;
            }
        }

        // Never trust the paths on disk blindly, uninstall deletes every one of them.
        $files = [];
        foreach (is_array($metadata['files'] ?? null) ? $metadata['files'] : [] as $file) {
            if (!is_string($file)) {
                continue;
            }

            try {
                $files[] = $this->validateRelativePath($file);
            } catch (ModpackException) {
                continue;
            }
        }

        $metadata['files'] = array_values(array_unique($files));
        $metadata['hashes'] = array_filter(is_array($metadata['hashes'] ?? null) ? $metadata['hashes'] : [], fn ($hash, $path) => is_string($hash) && is_string($path), ARRAY_FILTER_USE_BOTH);

        return $metadata;
    }

    /** @param  InstalledModpack  $metadata */
    public function saveModpackMetadata(Server $server, array $metadata): bool
    {
        try {
            return Cache::lock("modrinth_modpack_metadata:{$server->id}", 10)->block(5, function () use ($server, $metadata) {
                $response = app(DaemonFileRepository::class)->setServer($server)->putContent(
                    self::METADATA_FILE,
                    json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );

                return !$response->failed();
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModpackMetadata(Server $server): bool
    {
        try {
            return Cache::lock("modrinth_modpack_metadata:{$server->id}", 10)->block(5, function () use ($server) {
                $this->deleteFiles($server, [self::METADATA_FILE]);

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * List the mods of a modpack in the installed list of the minecraft-modrinth plugin, so they
     * show up in its "Installed Mods" tab.
     *
     * Entries are marked with the modpack that added them. Entries of the modpacks in $replaceModpacks
     * are dropped first, everything the user installed on their own is left alone. Mods that aren't
     * on Modrinth can't be listed and are skipped.
     *
     * @param  array<string, string>  $files  sha1 hash of every file the modpack placed, by path
     * @param  array<int, string>  $replaceModpacks  project ids of modpacks whose entries to replace
     *
     * @throws Exception
     */
    public function syncModrinthModsMetadata(Server $server, ?string $modpackProjectId, array $files, array $replaceModpacks): void
    {
        if (!MinecraftModrinthIntegration::isModrinthPluginInstalled()) {
            return;
        }

        // minecraft-modrinth only knows jars right inside the mods folder.
        $mods = [];
        foreach ($files as $path => $sha1) {
            if (preg_match('#^mods/([^/]+\.jar)$#i', $path, $matches)) {
                $mods[strtolower($sha1)] = $matches[1];
            }
        }

        $entries = $modpackProjectId ? $this->resolveModrinthMods($mods, $modpackProjectId) : [];
        $replaceModpacks = array_values(array_filter($replaceModpacks));

        $this->updateModrinthModsMetadata($server, function (?array $existing) use ($entries, $replaceModpacks) {
            $installed = [];
            $replaced = [];
            foreach ($existing ?? [] as $entry) {
                if (is_array($entry) && in_array($entry[self::MODPACK_ENTRY_KEY] ?? null, $replaceModpacks, true)) {
                    $replaced[$entry['project_id'] ?? ''] = $entry;

                    continue;
                }

                $installed[] = $entry;
            }

            if ($existing === null && empty($entries)) {
                return null;
            }

            $taken = array_column(array_filter($installed, 'is_array'), 'project_id');

            foreach ($entries as $entry) {
                // The user installed this mod on their own, their entry wins.
                if (in_array($entry['project_id'], $taken, true)) {
                    continue;
                }

                $entry['installed_at'] = $replaced[$entry['project_id']]['installed_at'] ?? $entry['installed_at'];

                $installed[] = $entry;
                $taken[] = $entry['project_id'];
            }

            return $installed;
        });
    }

    /**
     * Change the installed list of the minecraft-modrinth plugin under the same lock it uses itself.
     *
     * $change gets the raw entries (null while the file doesn't exist) and returns the entries to
     * write, or null to leave the file as it is. Entries it doesn't know are passed along untouched.
     *
     * @param  callable(array<int, mixed>|null): (array<int, mixed>|null)  $change
     *
     * @throws Exception
     */
    public function updateModrinthModsMetadata(Server $server, callable $change): void
    {
        Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $change) {
            $fileRepository = app(DaemonFileRepository::class)->setServer($server);

            // Anything but a missing file must abort, rewriting the file from scratch would lose the user's mods.
            try {
                $content = $fileRepository->getContent(self::MODRINTH_MODS_METADATA_FILE);
            } catch (FileNotFoundException) {
                $content = null;
            }

            $metadata = $content === null ? null : json_decode($content, true);

            // json_decode() returns null for broken JSON too, which must not pass for a missing file.
            if ($content !== null && !is_array($metadata)) {
                throw new Exception(self::MODRINTH_MODS_METADATA_FILE.' is not valid JSON, leaving it alone');
            }

            $existing = $metadata === null ? null : (is_array($metadata['installed_mods'] ?? null) ? array_values($metadata['installed_mods']) : []);

            $installed = $change($existing);

            if ($installed === null) {
                return true;
            }

            $fileRepository->putContent(
                self::MODRINTH_MODS_METADATA_FILE,
                json_encode(['installed_mods' => array_values($installed)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            )->throw();

            return true;
        });
    }

    /**
     * Look up which Modrinth project and version each mod file belongs to, by its hash.
     *
     * @param  array<string, string>  $mods  file name by sha1 hash
     * @return array<int, array<string, string>> entries for the installed list of minecraft-modrinth
     *
     * @throws Exception
     */
    protected function resolveModrinthMods(array $mods, string $modpackProjectId): array
    {
        if (empty($mods)) {
            return [];
        }

        $versions = [];
        foreach (array_chunk(array_keys($mods), 500) as $chunk) {
            $response = Http::asJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->throw()
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $chunk,
                    'algorithm' => 'sha1',
                ])
                ->json();

            if (is_array($response)) {
                $versions += $response;
            }
        }

        $projectIds = array_values(array_unique(array_filter(array_map(fn ($version) => is_array($version) ? ($version['project_id'] ?? null) : null, $versions))));

        $projects = [];
        foreach (array_chunk($projectIds, 100) as $chunk) {
            $response = Http::asJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)])
                ->json();

            foreach (is_array($response) ? $response : [] as $project) {
                if (is_array($project) && isset($project['id'], $project['slug'], $project['title'])) {
                    $projects[$project['id']] = $project;
                }
            }
        }

        $now = now()->toIso8601String();
        $entries = [];

        foreach ($mods as $sha1 => $filename) {
            $version = $versions[$sha1] ?? null;
            $project = is_array($version) ? ($projects[$version['project_id'] ?? ''] ?? null) : null;

            if (!$project || !isset($version['id']) || isset($entries[$project['id']])) {
                continue;
            }

            $entries[$project['id']] = [
                'project_id' => $project['id'],
                'project_slug' => $project['slug'],
                'project_title' => $project['title'],
                'version_id' => $version['id'],
                'version_number' => (string) ($version['version_number'] ?? ''),
                'filename' => $filename,
                'installed_at' => $now,
                self::MODPACK_ENTRY_KEY => $modpackProjectId,
            ];
        }

        return array_values($entries);
    }

    /**
     * Ask the daemon to download a file and wait until it is actually on disk.
     *
     * Without `foreground` the daemon downloads in the background, so the panel gets a success
     * response before the file exists and never hears about a failed download. The explicit file
     * name matters too: otherwise the daemon derives it from the URL, which can differ from the
     * name in the modpack index.
     *
     * @return array<string, mixed> the daemon's directory entry of the downloaded file
     *
     * @throws Exception
     */
    public function downloadFile(Server $server, string $url, string $folder, string $filename): array
    {
        // If the name is already taken, finding it there afterwards says nothing about whether our download replaced it.
        $nameWasTaken = $this->findFile($server, $folder, $filename) !== null;

        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->getHttpClient()
                // The daemon holds the request open for the whole download, which easily outlives
                // the timeout used for the small file operations everything else here does.
                ->timeout(max((int) config('panel.guzzle.timeout'), self::DOWNLOAD_TIMEOUT))
                ->post("/api/servers/{$server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => $folder === '' ? '/' : $folder,
                    'file_name' => $filename,
                    'foreground' => true,
                ])
                ->throw();
        } catch (Exception $exception) {
            // A slow download can outlive our request while still finishing on the node, but a
            // file appearing under a name that was free before is the only proof of that we get.
            if ($nameWasTaken) {
                throw $exception;
            }

            try {
                $landed = $this->findFile($server, $folder, $filename);
            } catch (Exception) {
                throw $exception;
            }

            return $landed ?? throw $exception;
        }

        return $this->findFile($server, $folder, $filename)
            ?? throw new Exception("Daemon reported success but $folder/$filename is missing after downloading $url");
    }

    /**
     * @throws Exception
     */
    public function putFile(Server $server, string $path, string $contents): void
    {
        app(DaemonFileRepository::class)->setServer($server)->putContent($path, $contents)->throw();
    }

    /**
     * Turn a failed daemon call into something a user can act on, e.g. the daemon's own
     * "not enough disk space" instead of a bare exception.
     */
    public function describeDaemonError(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $error = $exception->response->json('error') ?? $exception->response->json('errors.0.detail');

            return trim("HTTP {$exception->response->status()}: ".(is_string($error) ? $error : $exception->response->reason()));
        }

        if ($exception instanceof ConnectionException) {
            return trans('minecraft-modpack-modrinth::strings.errors.daemon_unreachable');
        }

        return $exception->getMessage();
    }

    /**
     * @param  array<int, string>  $paths  paths relative to the server root
     *
     * @throws Exception
     */
    public function deleteFiles(Server $server, array $paths): void
    {
        foreach (array_chunk(array_values($paths), 100) as $chunk) {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->deleteFiles('/', $chunk)
                ->throw();
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function findFile(Server $server, string $folder, string $filename): ?array
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder === '' ? '/' : $folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist yet.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }

    public function canCreateBackups(Server $server): bool
    {
        return $server->backup_limit > 0 && (user()?->can('backup.create', $server) ?? false);
    }

    public function isBackupLimitReached(Server $server): bool
    {
        // Same rule the panel uses: failed backups don't count towards the limit.
        $nonFailed = $server->backups()
            ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('is_successful', true))
            ->count();

        return $nonFailed >= $server->backup_limit;
    }

    /**
     * @throws Exception
     */
    public function createBackup(Server $server, string $name): Backup
    {
        return app(InitiateBackupService::class)->handle($server, $name);
    }

    protected function getProgressCacheKey(Server|int $server): string
    {
        $serverId = $server instanceof Server ? $server->id : $server;

        return "modrinth_modpack_progress:$serverId";
    }

    /** @return ModpackProgress|null */
    public function getProgress(Server $server): ?array
    {
        $progress = cache()->get($this->getProgressCacheKey($server));

        if (!is_array($progress) || !isset($progress['status'])) {
            return null;
        }

        if (in_array($progress['status'], self::ACTIVE_STATUSES, true) && ($progress['updated_at'] ?? 0) < now()->subMinutes(self::STALE_PROGRESS_MINUTES)->timestamp) {
            $progress['status'] = 'failed';
            $progress['message'] = trans('minecraft-modpack-modrinth::strings.errors.stale');
        }

        return $progress;
    }

    /** @param  array<string, mixed>  $progress */
    public function setProgress(Server|int $server, array $progress): void
    {
        cache()->put($this->getProgressCacheKey($server), array_merge($progress, ['updated_at' => now()->timestamp]), now()->addDay());
    }

    /**
     * Merge changes into the stored progress. Calling it without changes only refreshes the
     * heartbeat so a long running step isn't mistaken for a dead job.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateProgress(Server|int $server, array $changes = []): void
    {
        $progress = cache()->get($this->getProgressCacheKey($server));

        $this->setProgress($server, array_merge(is_array($progress) ? $progress : [], $changes));
    }

    /**
     * Stop the active operation. A queued job exits as soon as it starts, a running one after the
     * file it is currently working on.
     */
    public function cancelOperation(Server $server): void
    {
        if (!$this->isOperationRunning($server)) {
            return;
        }

        $this->updateProgress($server, [
            'status' => 'cancelled',
            'message' => trans('minecraft-modpack-modrinth::strings.errors.cancelled'),
        ]);
    }

    /**
     * Whether the job of $operationId should stop: the user cancelled it, or the progress belongs to
     * another operation by now (it was dismissed, or the job is a stale leftover being retried).
     */
    public function isOperationCancelled(Server|int $server, string $operationId): bool
    {
        $progress = cache()->get($this->getProgressCacheKey($server));

        return !is_array($progress)
            || ($progress['operation_id'] ?? null) !== $operationId
            || ($progress['status'] ?? null) === 'cancelled';
    }

    public function clearProgress(Server $server): void
    {
        cache()->forget($this->getProgressCacheKey($server));
    }

    public function isOperationRunning(Server $server): bool
    {
        return in_array($this->getProgress($server)['status'] ?? null, self::ACTIVE_STATUSES, true);
    }
}
