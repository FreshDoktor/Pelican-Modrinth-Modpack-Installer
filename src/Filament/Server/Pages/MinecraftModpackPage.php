<?php

namespace CedricKassen\MinecraftModpackModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use CedricKassen\MinecraftModpackModrinth\Integration\MinecraftModrinthIntegration;
use CedricKassen\MinecraftModpackModrinth\Livewire\ModpackBrowser;
use CedricKassen\MinecraftModpackModrinth\Services\MinecraftModpackModrinthService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\On;

/**
 * Standalone modpack page, for servers where the minecraft-modrinth plugin doesn't show the
 * modpacks in the tabs of its mods page.
 */
class MinecraftModpackPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    // The table itself lives in ModpackBrowser, HasTabs just relies on the pagination this brings along.
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-box-multiple';

    protected static ?string $slug = 'modrinth_modpacks';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && MinecraftModpackModrinthService::isEnabledForServer($server)
            && !MinecraftModrinthIntegration::isShownOnModrinthPage();
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-modpack-modrinth::strings.minecraft_modpacks');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            ModpackBrowser::TAB_ALL => Tab::make(trans('minecraft-modpack-modrinth::strings.page.view_all')),
            ModpackBrowser::TAB_INSTALLED => Tab::make(trans('minecraft-modpack-modrinth::strings.page.view_installed')),
        ];
    }

    /** Re-render, so the info badges reflect what the finished operation changed. */
    #[On(ModpackBrowser::OPERATION_FINISHED_EVENT)]
    public function refreshModpackInfo(): void {}

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_folder')
                ->tooltip(trans('minecraft-modpack-modrinth::strings.page.open_folder'))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(), true),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $schema
            ->components([
                ModpackBrowser::makeInfoGrid($server),
                $this->getTabsContentComponent(),
                ModpackBrowser::make($server, $this->activeTab === ModpackBrowser::TAB_INSTALLED ? ModpackBrowser::TAB_INSTALLED : ModpackBrowser::TAB_ALL),
            ]);
    }
}
