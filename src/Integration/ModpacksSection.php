<?php

namespace CedricKassen\MinecraftModpackModrinth\Integration;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use CedricKassen\MinecraftModpackModrinth\Livewire\ModpackBrowser;
use CedricKassen\MinecraftModpackModrinth\ModrinthHub\ModrinthHub;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

/**
 * The modpacks tab of the Modrinth page, see ModrinthHub.
 */
class ModpacksSection
{
    public const HUB_PROTOCOL = ModrinthHub::PROTOCOL;

    public static function getId(): string
    {
        return 'modpacks';
    }

    public static function getSort(): int
    {
        return 20;
    }

    public static function getLabel(): string
    {
        return trans('minecraft-modpack-modrinth::strings.hub.label');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-modpack-modrinth::strings.hub.navigation_label');
    }

    public static function isAvailable(Server $server): bool
    {
        return MinecraftModpackModrinthService::isEnabledForServer($server);
    }

    public static function getInfoComponent(Server $server, bool $installedOnly): ?Component
    {
        return ModpackBrowser::makeInfoGrid($server);
    }

    public static function getContentComponent(Server $server, bool $installedOnly): Component
    {
        return ModpackBrowser::make($server, $installedOnly ? ModpackBrowser::TAB_INSTALLED : ModpackBrowser::TAB_ALL);
    }

    /** @return array<Action> */
    public static function getHeaderActions(Server $server): array
    {
        return [
            Action::make('modpacks_open_folder')
                ->tooltip(trans('minecraft-modpack-modrinth::strings.page.open_folder'))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(), true),
        ];
    }

    /** @return array<string> */
    public static function getRefreshEvents(): array
    {
        return [ModpackBrowser::OPERATION_FINISHED_EVENT];
    }
}
