<?php

namespace CedricKassen\MinecraftModpackModrinth\Facades;

use App\Models\Backup;
use App\Models\Server;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Illuminate\Support\Facades\Facade;

/**
 * @phpstan-import-type InstalledModpack from MinecraftModpackModrinthService
 * @phpstan-import-type ModpackProgress from MinecraftModpackModrinthService
 *
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static ?string getLatestMinecraftVersion()
 * @method static array{icon: string, name: string, supported_project_types: string[], display_name: string}|null getLoaderFromServer(Server $server)
 * @method static array<int, array{icon: string, name: string, supported_project_types: string[]}> getLoaders()
 * @method static array<int, string> getCategories()
 * @method static array{hits: array<int, array<string, mixed>>, total_hits: int} getProjects(Server $server, int $page = 1, ?string $search = null, array<int, string> $categories = [], string $sort = 'relevance')
 * @method static array<string, mixed> getInstalledModpackFromModrinth(array $installedModpack)
 * @method static array<int, array<string, mixed>> getProjectVersions(string $projectId, Server $server)
 * @method static array<string, mixed>|null getVersion(string $versionId)
 * @method static array{url: string, filename: string, size?: int, primary?: bool}|null getMrpackFile(array<int, mixed> $files)
 * @method static InstalledModpack|null getInstalledModpack(Server $server)
 * @method static bool saveModpackMetadata(Server $server, array $metadata)
 * @method static bool removeModpackMetadata(Server $server)
 * @method static bool canCreateBackups(Server $server)
 * @method static bool isBackupLimitReached(Server $server)
 * @method static Backup createBackup(Server $server, string $name)
 * @method static ModpackProgress|null getProgress(Server $server)
 * @method static void setProgress(Server|int $server, array<string, mixed> $progress)
 * @method static void updateProgress(Server|int $server, array<string, mixed> $changes = [])
 * @method static void clearProgress(Server $server)
 * @method static bool isOperationRunning(Server $server)
 *
 * @see MinecraftModpackModrinthService
 */
class MinecraftModpackModrinth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MinecraftModpackModrinthService::class;
    }
}
