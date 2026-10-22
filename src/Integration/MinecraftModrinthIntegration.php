<?php

namespace CedricKassen\MinecraftModpackModrinth\Integration;

use CedricKassen\MinecraftModpackModrinth\Filament\Server\ModrinthIntegration\MinecraftModsAndModpacksPage;
use Filament\Facades\Filament;
use Filament\Panel;
use Throwable;

/**
 * Glue to the optional minecraft-modrinth plugin. Its code is never changed, it is only referred
 * to by class name since it may not be installed.
 */
class MinecraftModrinthIntegration
{
    public const MODRINTH_PLUGIN = 'Boy132\MinecraftModrinth\MinecraftModrinthPlugin';

    public const MODRINTH_MOD_PAGE = 'Boy132\MinecraftModrinth\Filament\Server\Pages\MinecraftModrinthModPage';

    /**
     * Whether the minecraft-modrinth plugin is installed, regardless of the panel. Also works on the queue.
     */
    public static function isModrinthPluginInstalled(): bool
    {
        return class_exists(self::MODRINTH_PLUGIN);
    }

    /**
     * Register the combined mods and modpacks page. Has to happen while the panel registers its
     * pages, so its route exists.
     */
    public static function registerPages(Panel $panel): void
    {
        if (class_exists(self::MODRINTH_MOD_PAGE)) {
            $panel->pages([MinecraftModsAndModpacksPage::class]);
        }
    }

    /**
     * Take the original mods page out of the panel's page list, so only the combined page shows up
     * in the navigation. Its route stays registered, old links keep working.
     *
     * Filament has no API to remove a page, hence reaching into the panel. Should that ever stop
     * working, both pages are listed again, nothing breaks.
     */
    public static function hideOriginalModPage(Panel $panel): void
    {
        if (!in_array(MinecraftModsAndModpacksPage::class, $panel->getPages(), true)) {
            return;
        }

        try {
            (function () {
                if (property_exists($this, 'pages') && is_array($this->pages)) {
                    $this->pages = array_values(array_filter($this->pages, fn ($page) => $page !== MinecraftModrinthIntegration::MODRINTH_MOD_PAGE));
                }
            })->call($panel);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Whether the combined page shows the modpacks for the current server, making the standalone
     * modpack page redundant.
     */
    public static function isShownOnModrinthPage(): bool
    {
        if (!in_array(MinecraftModsAndModpacksPage::class, Filament::getCurrentPanel()?->getPages() ?? [], true)) {
            return false;
        }

        try {
            return MinecraftModsAndModpacksPage::canAccess();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
