<?php

namespace CedricKassen\MinecraftModpackModrinth\Filament\Server\ModrinthIntegration;

use App\Models\Server;
use Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage;
use CedricKassen\MinecraftModpackModrinth\Livewire\ModpackBrowser;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

/**
 * The mods page of the minecraft-modrinth plugin with the modpacks added as tabs.
 *
 * It takes the place of the original page in the navigation (see MinecraftModpackModrinthPlugin::boot),
 * so the minecraft-modrinth plugin itself stays untouched and can be updated as usual. Everything
 * about mods is inherited, only the modpack tabs are added.
 *
 * Lives outside of the discovered Pages folder because it can only be loaded when minecraft-modrinth is installed.
 */
class MinecraftModsAndModpacksPage extends MinecraftModrinthModPage
{
    // A slug of its own, the original page keeps its route.
    protected static ?string $slug = 'modrinth';

    /** Tabs added to the mods page, mapped to the list ModpackBrowser shows for them. */
    public const MODPACK_TABS = [
        'modpacks' => ModpackBrowser::TAB_ALL,
        'installed_modpacks' => ModpackBrowser::TAB_INSTALLED,
    ];

    /** Re-render, so the info badges reflect what the finished operation changed. */
    #[On(ModpackBrowser::OPERATION_FINISHED_EVENT)]
    public function refreshModpackInfo(): void {}

    protected function showsModpacks(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return MinecraftModpackModrinthService::isEnabledForServer($server);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        if (!$this->showsModpacks()) {
            return parent::getTabs();
        }

        return [
            'all' => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_mods')),
            'modpacks' => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_modpacks')),
            'installed' => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_installed_mods')),
            'installed_modpacks' => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_installed_modpacks')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        if (!$this->showsModpacks() || !isset(self::MODPACK_TABS[$this->activeTab])) {
            return parent::content($schema);
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        // Same layout as the mod tabs: info, tabs, then the list.
        return $schema
            ->components([
                ModpackBrowser::makeInfoGrid($server),
                $this->getTabsContentComponent(),
                ModpackBrowser::make($server, self::MODPACK_TABS[$this->activeTab]),
            ]);
    }
}
