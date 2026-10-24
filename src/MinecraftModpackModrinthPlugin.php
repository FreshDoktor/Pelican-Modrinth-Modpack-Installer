<?php

namespace CedricKassen\MinecraftModpackModrinth;

use CedricKassen\MinecraftModpackModrinth\Integration\ModpacksSection;
use CedricKassen\MinecraftModpackModrinth\Livewire\ModpackBrowser;
use CedricKassen\MinecraftModpackModrinth\ModrinthHub\ModrinthHub;
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

        // The modpacks are a tab of the Modrinth page, hosted by this plugin unless another one already does.
        ModrinthHub::registerSection(ModpacksSection::class);

        ModrinthHub::registerPage($panel);
    }

    public function boot(Panel $panel): void
    {
        // Runs once all plugins registered their pages, so the mods page of minecraft-modrinth is known by now.
        ModrinthHub::boot($panel);
    }
}
