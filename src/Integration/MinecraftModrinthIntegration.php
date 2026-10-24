<?php

namespace CedricKassen\MinecraftModpackModrinth\Integration;

use App\Models\Plugin;
use Throwable;

/**
 * Glue to the optional minecraft-modrinth plugin. Its code is never changed, it is only referred
 * to by class name since it may not be installed. Its mods page is combined with the modpacks by
 * the Modrinth page, see ModrinthHub.
 */
class MinecraftModrinthIntegration
{
    public const MODRINTH_PLUGIN_ID = 'minecraft-modrinth';

    public const MODRINTH_PLUGIN = 'Boy132\MinecraftModrinth\MinecraftModrinthPlugin';

    /**
     * Whether the minecraft-modrinth plugin is enabled, on the given panel or regardless of the panel.
     * Also works on the queue.
     *
     * The panel autoloads the classes of every plugin in the plugins folder, even of the ones that
     * aren't installed or are disabled, so their classes existing doesn't tell whether they are in use.
     */
    public static function isModrinthPluginEnabled(?string $panelId = null): bool
    {
        if (!class_exists(self::MODRINTH_PLUGIN)) {
            return false;
        }

        try {
            return Plugin::query()->find(self::MODRINTH_PLUGIN_ID)?->shouldLoad($panelId) ?? false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
