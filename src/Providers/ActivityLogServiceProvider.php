<?php

namespace CedricKassen\MinecraftModpackModrinth\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The activity log translates an event like server:modpack.install with the key activity.server.modpack.install
 * of the panel's own activity.php. An extra path makes the translator merge our activity.php into it.
 *
 * Registered by the panel, it loads every service provider in src/Providers of an enabled plugin.
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('translator', function ($translator) {
            $translator->addPath(dirname(__DIR__, 2).'/resources/activity-lang');
        });
    }
}
