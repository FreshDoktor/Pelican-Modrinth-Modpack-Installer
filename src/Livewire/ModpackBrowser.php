<?php

namespace CedricKassen\MinecraftModpackModrinth\Livewire;

use App\Facades\Activity;
use App\Models\Server;
use CedricKassen\MinecraftModpackModrinth\Facades\MinecraftModpackModrinth;
use CedricKassen\MinecraftModpackModrinth\Jobs\ModpackJob;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The modpack browser: badges, progress of a running operation and the table of modpacks.
 *
 * It is a component of its own so it can live on the standalone modpack page as well as in the
 * tabs of the mods page of the minecraft-modrinth plugin. The hosting page only provides the tabs.
 *
 * @phpstan-import-type InstalledModpack from MinecraftModpackModrinthService
 */
class ModpackBrowser extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public const TAB_ALL = 'all';

    public const TAB_INSTALLED = 'installed';

    /** Dispatched once an operation finished, so the hosting page can refresh its info badges. */
    public const OPERATION_FINISHED_EVENT = 'modpack-operation-finished';

    #[Locked]
    public int $serverId;

    /** Which list to show, one of the TAB_* constants. */
    #[Locked]
    public string $tab = self::TAB_ALL;

    /** Status of the operation as of the last request, used to notice when a job finished. */
    public ?string $trackedStatus = null;

    protected ?Server $server = null;

    /** @var InstalledModpack|null */
    protected ?array $installedModpack = null;

    protected bool $installedModpackLoaded = false;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    public static function make(Server $server, string $tab): LivewireComponent
    {
        return LivewireComponent::make(static::class, [
            'serverId' => $server->id,
            'tab' => $tab,
        ])->key("modrinth-modpacks-$tab");
    }

    /**
     * Minecraft version, loader and installed modpack. Rendered by the hosting page above its tabs,
     * the same place the mods page shows its info.
     */
    public static function makeInfoGrid(Server $server): Grid
    {
        return Grid::make(3)
            ->schema([
                TextEntry::make('minecraft_version')
                    ->label(trans('minecraft-modpack-modrinth::strings.page.minecraft_version'))
                    ->state(fn () => MinecraftModpackModrinth::getMinecraftVersion($server) ?? trans('minecraft-modpack-modrinth::strings.page.unknown'))
                    ->badge(),
                TextEntry::make('loader')
                    ->label(trans('minecraft-modpack-modrinth::strings.page.loader'))
                    ->state(fn () => MinecraftModpackModrinth::getLoaderFromServer($server)['display_name'] ?? trans('minecraft-modpack-modrinth::strings.page.unknown'))
                    ->icon(fn () => new HtmlString(MinecraftModpackModrinth::getLoaderFromServer($server)['icon'] ?? ''))
                    ->badge(),
                TextEntry::make('installed_modpack')
                    ->label(trans('minecraft-modpack-modrinth::strings.page.installed_modpack'))
                    ->state(function () use ($server) {
                        $installed = MinecraftModpackModrinth::getInstalledModpack($server);

                        return $installed
                            ? "{$installed['project_title']} {$installed['version_number']}"
                            : trans('minecraft-modpack-modrinth::strings.page.none');
                    })
                    ->badge(),
            ]);
    }

    public function mount(int $serverId, string $tab = self::TAB_ALL): void
    {
        $this->serverId = $serverId;
        $this->tab = $tab === self::TAB_INSTALLED ? self::TAB_INSTALLED : self::TAB_ALL;

        $this->authorizeAccess();

        $this->trackedStatus = $this->getProgress()['status'] ?? null;
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();

        $this->checkForFinishedOperation();
    }

    /**
     * Updates of this component don't pass through the page that rendered it, so check again
     * that the user may still access the server.
     */
    protected function authorizeAccess(): void
    {
        $server = $this->getServer();

        abort_unless(user()?->canAccessTenant($server) && MinecraftModpackModrinthService::isEnabledForServer($server), 403);
    }

    protected function getServer(): Server
    {
        return $this->server ??= Server::findOrFail($this->serverId);
    }

    /** @return InstalledModpack|null */
    protected function getInstalledModpack(): ?array
    {
        if (!$this->installedModpackLoaded) {
            $this->installedModpack = MinecraftModpackModrinth::getInstalledModpack($this->getServer());
            $this->installedModpackLoaded = true;
        }

        return $this->installedModpack;
    }

    protected function isInstalled(string $projectId): bool
    {
        return ($this->getInstalledModpack()['project_id'] ?? null) === $projectId;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            $this->versionsCache[$projectId] = MinecraftModpackModrinth::getProjectVersions($projectId, $this->getServer());
        }

        return $this->versionsCache[$projectId];
    }

    /** @return array<string, mixed>|null */
    protected function getProgress(): ?array
    {
        return MinecraftModpackModrinth::getProgress($this->getServer());
    }

    protected function isOperationRunning(): bool
    {
        return MinecraftModpackModrinth::isOperationRunning($this->getServer());
    }

    /**
     * Drop everything read from the server so the next render reflects what is actually on disk.
     */
    protected function forgetInstalledState(): void
    {
        $this->installedModpack = null;
        $this->installedModpackLoaded = false;
        $this->versionsCache = [];
    }

    /**
     * The job reports its progress through the cache. Once an operation this page has seen running
     * isn't anymore, tell the user how it went.
     */
    protected function checkForFinishedOperation(): void
    {
        $progress = $this->getProgress();
        $status = $progress['status'] ?? null;

        $wasActive = in_array($this->trackedStatus, MinecraftModpackModrinthService::ACTIVE_STATUSES, true);
        $isActive = in_array($status, MinecraftModpackModrinthService::ACTIVE_STATUSES, true);

        $this->trackedStatus = $status;

        if (!$wasActive || $isActive) {
            return;
        }

        $this->forgetInstalledState();

        $this->dispatch(self::OPERATION_FINISHED_EVENT);

        // Whoever cancelled already got told so.
        if ($status === 'cancelled') {
            return;
        }

        $operation = $progress['operation'] ?? ModpackJob::OPERATION_INSTALL;

        if ($status === 'done') {
            Notification::make()
                ->title(trans("minecraft-modpack-modrinth::strings.notifications.{$operation}_success"))
                ->body(trans("minecraft-modpack-modrinth::strings.notifications.{$operation}_success_body", [
                    'name' => $progress['title'] ?? '',
                    'version' => $progress['version_number'] ?? '',
                ]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans("minecraft-modpack-modrinth::strings.notifications.{$operation}_failed"))
            ->body($progress['message'] ?? trans('minecraft-modpack-modrinth::strings.errors.unexpected'))
            ->danger()
            ->persistent()
            ->send();
    }

    protected function canCreateBackups(): bool
    {
        return MinecraftModpackModrinth::canCreateBackups($this->getServer());
    }

    protected function isBackupLimitReached(): bool
    {
        return MinecraftModpackModrinth::isBackupLimitReached($this->getServer());
    }

    protected function getBackupToggle(): Toggle
    {
        return Toggle::make('create_backup')
            ->label(trans('minecraft-modpack-modrinth::strings.backup.toggle'))
            ->helperText(fn () => $this->isBackupLimitReached()
                ? trans('minecraft-modpack-modrinth::strings.backup.limit_reached')
                : trans('minecraft-modpack-modrinth::strings.backup.helper'))
            ->default(fn () => !$this->isBackupLimitReached())
            ->disabled(fn () => $this->isBackupLimitReached())
            ->visible(fn () => $this->canCreateBackups());
    }

    protected function withStopServerHint(string $description): HtmlString
    {
        return new HtmlString(e($description).'<br><br><strong>'.e(trans('minecraft-modpack-modrinth::strings.modals.stop_server_hint')).'</strong>');
    }

    /**
     * Describe what installing $record will do, warning when it replaces another pack.
     *
     * @param  array<string, mixed>  $record
     */
    protected function getInstallDescription(array $record, string $versionNumber): HtmlString
    {
        $installed = $this->getInstalledModpack();

        if ($installed && $installed['project_id'] !== $record['project_id']) {
            return $this->withStopServerHint(trans('minecraft-modpack-modrinth::strings.modals.replace_description', [
                'old' => $installed['project_title'],
                'new' => $record['title'],
                'version' => $versionNumber,
            ]));
        }

        if ($installed) {
            return $this->withStopServerHint(trans('minecraft-modpack-modrinth::strings.modals.update_description', [
                'old_version' => $installed['version_number'],
                'new_version' => $versionNumber,
            ]));
        }

        return $this->withStopServerHint(trans('minecraft-modpack-modrinth::strings.modals.install_description', [
            'name' => $record['title'],
            'version' => $versionNumber,
        ]));
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     */
    protected function queueOperation(string $operation, array $record, ?string $versionId, ?string $versionNumber, array $data): void
    {
        $server = $this->getServer();

        if ($this->isOperationRunning()) {
            Notification::make()
                ->title(trans('minecraft-modpack-modrinth::strings.errors.already_running'))
                ->warning()
                ->send();

            return;
        }

        // A disabled or hidden toggle isn't submitted, so only an explicit yes counts.
        $createBackup = !empty($data['create_backup']) && $this->canCreateBackups();

        $operationId = (string) Str::uuid();

        try {
            MinecraftModpackModrinth::setProgress($server, [
                'operation_id' => $operationId,
                'status' => 'queued',
                'operation' => $operation,
                'step' => $createBackup ? 'queued_with_backup' : 'queued',
                'current' => null,
                'total' => null,
                'message' => null,
                'title' => $record['title'],
                'version_number' => $versionNumber,
            ]);

            ModpackJob::dispatch($server->id, $operationId, $operation, [
                'id' => $record['project_id'],
                'slug' => $record['slug'],
                'title' => $record['title'],
                'author' => $record['author'] ?? null,
            ], $versionId, $createBackup, user()?->id);
        } catch (Exception $exception) {
            report($exception);

            MinecraftModpackModrinth::clearProgress($server);

            Notification::make()
                ->title(trans("minecraft-modpack-modrinth::strings.notifications.{$operation}_failed"))
                ->body(trans('minecraft-modpack-modrinth::strings.errors.unexpected'))
                ->danger()
                ->send();

            return;
        }

        $this->trackedStatus = 'queued';

        Activity::event("server:modpack.$operation")
            ->subject($server)
            ->property([
                'name' => $record['title'],
                'version' => $versionNumber ?? '',
            ])
            ->log();

        Notification::make()
            ->title(trans('minecraft-modpack-modrinth::strings.notifications.queued'))
            ->body(trans('minecraft-modpack-modrinth::strings.notifications.queued_body', ['name' => $record['title']]))
            ->info()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $data
     */
    protected function queueInstall(array $record, array $versionData, array $data): void
    {
        $operation = $this->isInstalled($record['project_id']) ? ModpackJob::OPERATION_UPDATE : ModpackJob::OPERATION_INSTALL;

        $this->queueOperation($operation, $record, $versionData['id'], $versionData['version_number'] ?? null, $data);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                $server = $this->getServer();

                // The page name follows the query string identifier, the pagination links of the
                // table switch the page under the paginator's name.
                $paginate = fn (array $items, int $total) => new LengthAwarePaginator($items, $total, 20, $page, [
                    'pageName' => $this->getTablePaginationPageName(),
                ]);

                if ($this->tab === 'installed') {
                    $installed = $this->getInstalledModpack();

                    if (!$installed || $page > 1) {
                        return $paginate([], $installed ? 1 : 0);
                    }

                    if ($search) {
                        $searchLower = strtolower($search);

                        if (!str_contains(strtolower($installed['project_title']), $searchLower) && !str_contains(strtolower($installed['project_slug']), $searchLower)) {
                            return $paginate([], 0);
                        }
                    }

                    return $paginate([MinecraftModpackModrinth::getInstalledModpackFromModrinth($installed)], 1);
                }

                $response = MinecraftModpackModrinth::getProjects($server, $page, $search);

                return $paginate($response['hits'], $response['total_hits']);
            })
            ->paginated([20])
            // Keep the query string apart from the table of a page this is embedded in.
            ->queryStringIdentifier('modpacks')
            ->poll(fn () => $this->isOperationRunning() ? '3s' : null)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->label(trans('minecraft-modpack-modrinth::strings.table.columns.title'))
                    ->searchable()
                    ->description(fn (array $record) => (strlen($record['description'] ?? '') > 120) ? substr($record['description'], 0, 120).'...' : ($record['description'] ?? '')),
                TextColumn::make('author')
                    ->label(trans('minecraft-modpack-modrinth::strings.table.columns.author'))
                    ->url(fn ($state) => $state ? "https://modrinth.com/user/$state" : null, true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->label(trans('minecraft-modpack-modrinth::strings.table.columns.downloads'))
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->label(trans('minecraft-modpack-modrinth::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable'])) {
                    return null;
                }

                return "https://modrinth.com/modpack/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('minecraft-modpack-modrinth::strings.actions.versions'))
                    ->visible(fn (array $record) => empty($record['unavailable']))
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installed = $this->getInstalledModpack();
                        $installedVersionId = $this->isInstalled($record['project_id']) ? $installed['version_id'] : null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $mrpack = MinecraftModpackModrinth::getMrpackFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('minecraft-modpack-modrinth::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->visible($mrpack !== null)
                                    ->disabled(fn () => $this->isOperationRunning())
                                    ->requiresConfirmation()
                                    ->modalHeading(trans('minecraft-modpack-modrinth::strings.modals.install_heading'))
                                    ->modalDescription(fn () => $this->getInstallDescription($record, $versionData['version_number'] ?? ''))
                                    ->schema([$this->getBackupToggle()])
                                    ->action(fn (array $data) => $this->queueInstall($record, $versionData, $data));
                                $sectionIcon = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor('success');
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('minecraft-modpack-modrinth::strings.actions.install_latest'))
                    ->visible(fn (array $record) => empty($record['unavailable']) && !$this->isInstalled($record['project_id']))
                    ->disabled(fn () => $this->isOperationRunning())
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modpack-modrinth::strings.modals.install_heading'))
                    ->modalDescription(fn (array $record) => $this->getInstallDescription($record, $this->getCachedVersions($record['project_id'])[0]['version_number'] ?? trans('minecraft-modpack-modrinth::strings.page.unknown')))
                    ->schema([$this->getBackupToggle()])
                    ->action(function (array $record, array $data) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            Notification::make()
                                ->title(trans('minecraft-modpack-modrinth::strings.notifications.install_failed'))
                                ->body(trans('minecraft-modpack-modrinth::strings.errors.no_compatible_version'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->queueInstall($record, $versions[0], $data);
                    }),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('minecraft-modpack-modrinth::strings.actions.update'))
                    ->visible(function (array $record) {
                        if (!$this->isInstalled($record['project_id'])) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        return !empty($versions) && $this->getInstalledModpack()['version_id'] !== $versions[0]['id'];
                    })
                    ->disabled(fn () => $this->isOperationRunning())
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modpack-modrinth::strings.modals.update_heading'))
                    ->modalDescription(fn (array $record) => $this->getInstallDescription($record, $this->getCachedVersions($record['project_id'])[0]['version_number'] ?? trans('minecraft-modpack-modrinth::strings.page.unknown')))
                    ->schema([$this->getBackupToggle()])
                    ->action(function (array $record, array $data) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        if (empty($versions)) {
                            Notification::make()
                                ->title(trans('minecraft-modpack-modrinth::strings.notifications.update_failed'))
                                ->body(trans('minecraft-modpack-modrinth::strings.errors.no_compatible_version'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->queueOperation(ModpackJob::OPERATION_UPDATE, $record, $versions[0]['id'], $versions[0]['version_number'] ?? null, $data);
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('minecraft-modpack-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->visible(function (array $record) {
                        if (!$this->isInstalled($record['project_id'])) {
                            return false;
                        }

                        $versions = $this->getCachedVersions($record['project_id']);

                        return empty($versions) || $this->getInstalledModpack()['version_id'] === $versions[0]['id'];
                    }),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-modpack-modrinth::strings.actions.uninstall'))
                    ->visible(fn (array $record) => $this->isInstalled($record['project_id']))
                    ->disabled(fn () => $this->isOperationRunning())
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modpack-modrinth::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => $this->withStopServerHint(trans('minecraft-modpack-modrinth::strings.modals.uninstall_description', [
                        'name' => $record['title'],
                        'count' => count($this->getInstalledModpack()['files'] ?? []),
                    ])))
                    ->schema([$this->getBackupToggle()])
                    ->action(fn (array $record, array $data) => $this->queueOperation(
                        ModpackJob::OPERATION_UNINSTALL,
                        $record,
                        null,
                        $this->getInstalledModpack()['version_number'] ?? null,
                        $data
                    )),
            ]);
    }

    protected function getProgressSection(): Section
    {
        return Section::make(fn () => trans('minecraft-modpack-modrinth::strings.progress.heading', [
            'operation' => trans('minecraft-modpack-modrinth::strings.operations.'.($this->getProgress()['operation'] ?? ModpackJob::OPERATION_INSTALL)),
            'name' => trim(($this->getProgress()['title'] ?? '').' '.($this->getProgress()['version_number'] ?? '')),
        ]))
            ->visible(fn () => $this->getProgress() !== null)
            ->icon(fn () => match ($this->getProgress()['status'] ?? null) {
                'done' => 'tabler-circle-check',
                'failed' => 'tabler-alert-triangle',
                'cancelled' => 'tabler-circle-x',
                default => 'tabler-loader-2',
            })
            ->iconColor(fn () => match ($this->getProgress()['status'] ?? null) {
                'done' => 'success',
                'failed' => 'danger',
                'cancelled' => 'gray',
                default => 'info',
            })
            ->headerActions([
                Action::make('cancel_operation')
                    ->label(trans('minecraft-modpack-modrinth::strings.progress.cancel'))
                    ->icon('tabler-player-stop')
                    ->color('danger')
                    ->visible(fn () => $this->isOperationRunning())
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modpack-modrinth::strings.modals.cancel_heading'))
                    ->modalDescription(trans('minecraft-modpack-modrinth::strings.modals.cancel_description'))
                    ->action(function () {
                        $progress = $this->getProgress();

                        MinecraftModpackModrinth::cancelOperation($this->getServer());

                        Activity::event('server:modpack.cancel')
                            ->subject($this->getServer())
                            ->property([
                                'name' => $progress['title'] ?? '',
                                'operation' => $progress['operation'] ?? '',
                            ])
                            ->log();

                        $this->trackedStatus = 'cancelled';
                        $this->forgetInstalledState();

                        Notification::make()
                            ->title(trans('minecraft-modpack-modrinth::strings.notifications.cancelled'))
                            ->success()
                            ->send();
                    }),
                Action::make('dismiss_progress')
                    ->label(trans('minecraft-modpack-modrinth::strings.progress.dismiss'))
                    ->icon('tabler-x')
                    ->color('gray')
                    ->visible(fn () => !$this->isOperationRunning())
                    ->action(fn () => MinecraftModpackModrinth::clearProgress($this->getServer())),
            ])
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextEntry::make('progress_status')
                            ->label(trans('minecraft-modpack-modrinth::strings.progress.status'))
                            ->state(fn () => trans('minecraft-modpack-modrinth::strings.progress.statuses.'.($this->getProgress()['status'] ?? 'queued')))
                            ->badge()
                            ->color(fn () => match ($this->getProgress()['status'] ?? null) {
                                'done' => 'success',
                                'failed' => 'danger',
                                'running' => 'info',
                                default => 'gray',
                            }),
                        TextEntry::make('progress_step')
                            ->label(trans('minecraft-modpack-modrinth::strings.progress.step'))
                            ->state(function () {
                                $progress = $this->getProgress() ?? [];

                                $step = trans('minecraft-modpack-modrinth::strings.progress.steps.'.($progress['step'] ?? 'queued'));

                                if (isset($progress['current'], $progress['total']) && in_array($progress['status'] ?? null, MinecraftModpackModrinthService::ACTIVE_STATUSES, true)) {
                                    $step .= " ({$progress['current']}/{$progress['total']})";
                                }

                                return $step;
                            }),
                        TextEntry::make('progress_message')
                            ->label(trans('minecraft-modpack-modrinth::strings.progress.message'))
                            ->state(fn () => $this->getProgress()['message'] ?? null)
                            ->visible(fn () => !empty($this->getProgress()['message']))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getProgressSection(),
                EmbeddedTable::make(),
            ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div class="flex flex-col gap-y-6">
                {{ $this->content }}

                <x-filament-actions::modals />
            </div>
        BLADE;
    }
}
