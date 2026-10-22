<?php

namespace CedricKassen\MinecraftModpackModrinth;

use CedricKassen\MinecraftModpackModrinth\Integration\MinecraftModrinthIntegration;
use CedricKassen\MinecraftModpackModrinth\Livewire\ModpackBrowser;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Livewire\Livewire;

class MinecraftModpackModrinthPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-modpack-modrinth';
    }

    public function register(Panel $panel): void
    {
        // Registered by name so Livewire finds the component again on updates, wherever it got embedded.
        Livewire::component('minecraft-modpack-modrinth.modpack-browser', ModpackBrowser::class);

        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "CedricKassen\\MinecraftModpackModrinth\\Filament\\$id\\Pages");

        // With minecraft-modrinth installed, the modpacks become tabs of its mods page.
        MinecraftModrinthIntegration::registerPages($panel);
    }

    public function boot(Panel $panel): void
    {
        // Runs once all plugins registered their pages, so the original mods page is known by now.
        MinecraftModrinthIntegration::hideOriginalModPage($panel);
    }
}
