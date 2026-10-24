<?php

namespace CedricKassen\MinecraftModpackModrinth\Filament\Server\ModrinthIntegration;

use App\Models\Server;
use Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage;
use CedricKassen\MinecraftModpackModrinth\Livewire\InstalledModsBrowser;
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
 * so the minecraft-modrinth plugin itself stays untouched and can be updated as usual. Browsing and
 * installing mods is inherited, the modpack tabs are added and the installed mods tab is replaced
 * by one that also lists the mods uploaded by hand.
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

    /** The tab of the mods page that InstalledModsBrowser takes over. */
    public const INSTALLED_MODS_TAB = 'installed';

    /** Re-render, so the info badges reflect what the finished operation or the changed mods folder look like now. */
    #[On(ModpackBrowser::OPERATION_FINISHED_EVENT)]
    #[On(InstalledModsBrowser::MODS_CHANGED_EVENT)]
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
            self::INSTALLED_MODS_TAB => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_installed_mods')),
            'installed_modpacks' => Tab::make(trans('minecraft-modpack-modrinth::strings.page.tab_installed_modpacks')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        if (!$this->showsModpacks()) {
            return parent::content($schema);
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        // Same layout as the mod tabs: info, tabs, then the list.
        if ($this->activeTab === self::INSTALLED_MODS_TAB) {
            return $schema
                ->components([
                    InstalledModsBrowser::makeInfoGrid($server),
                    $this->getTabsContentComponent(),
                    InstalledModsBrowser::make($server),
                ]);
        }

        if (!isset(self::MODPACK_TABS[$this->activeTab])) {
            return parent::content($schema);
        }

        return $schema
            ->components([
                ModpackBrowser::makeInfoGrid($server),
                $this->getTabsContentComponent(),
                ModpackBrowser::make($server, self::MODPACK_TABS[$this->activeTab]),
            ]);
    }
}
