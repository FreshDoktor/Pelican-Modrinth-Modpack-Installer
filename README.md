# Minecraft Modpack Modrinth

A [Pelican Panel](https://pelican.dev) plugin to install, update and manage Minecraft modpacks from [Modrinth](https://modrinth.com) directly within the server panel.

Originally written by Cedric Kassen. The overview of every installed mod, including the ones uploaded by hand, is a plugin of its own now: [Minecraft Mods Addon](https://github.com/FreshDoktor/Pelican-Modrinth-Mods-Addon), an addon for the Minecraft Modrinth plugin by Boy132.

## Features

### Modpacks

- **Browse and Search**: Search Modrinth's modpacks compatible with your server's loader and Minecraft version
- **Filter and Sort**: Narrow the list down by Modrinth's categories (technology, magic, adventure, quests, ...) and sort by relevance, downloads, followers, newest or recently updated
- **Background Installation**: Downloads every server-side file of the `.mrpack` and applies its `overrides` and `server-overrides`, with live progress on the page; running operations can be cancelled
- **Backups**: Optionally create a server backup before installing, updating or uninstalling; nothing is touched unless the backup succeeded
- **Update Detection**: Unchanged files are skipped on update, files dropped by the new version are removed
- **Easy Uninstall**: Removes every file the modpack placed, worlds and other files are kept
- **Safe by Default**: Only downloads from the hosts allowed by the `.mrpack` specification and rejects paths escaping the server directory

### General

- **One Modrinth page**: Mods (Minecraft Modrinth), modpacks and datapacks (Minecraft Datapack Modrinth) share one navigation entry with a tab each and a toggle to only show what is installed, as far as the plugins are enabled
- **Multilingual**: Supports English and German translations

## Requirements

- Pelican Panel with plugin support
- A Minecraft egg with a loader supported by Modrinth modpacks (Fabric, Quilt, Forge or NeoForge)
- A running queue worker (see [Setup](#setup))
- Optional: the [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth), [Minecraft Datapack Modrinth](https://github.com/FreshDoktor/Pelican-Minecraft-Datapack-Installer) and [Minecraft Mods Addon](https://github.com/FreshDoktor/Pelican-Modrinth-Mods-Addon) plugins

## Installation

Clone the repository into the `plugins` folder of your panel. The folder has to be named like the plugin id:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer.git minecraft-modpack-modrinth
```

Then install and enable the plugin in the admin area of the panel under _Plugins_.

## Setup

The modpacks use the same egg feature as the mods page of the Minecraft Modrinth plugin: add `modrinth_mods` to the _features_ of your egg (or `mods` together with the `minecraft` tag).
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name that supports modpacks (e.g. `fabric`, `quilt`, `forge` or `neoforge`).

Installing, updating and uninstalling modpacks runs in the background, so the panel's **queue worker must be running** (`pelican-queue` service, or `php artisan queue:work`).

The server itself is not changed: install the matching loader and Minecraft version through the egg yourself. Only modpack versions matching the server's loader and `MINECRAFT_VERSION` are listed.

### The Modrinth page

Modpacks are shown on a page that combines everything from Modrinth in one navigation entry, with a tab each for _Mods_, _Modpacks_ and _Datapacks_ and an _Only show installed_ toggle below the tabs:

| Plugins enabled | Tabs | Navigation entry |
| --- | --- | --- |
| Only this one | Modpacks | Modrinth Modpacks |
| With [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) | Mods, Modpacks | Modrinth |
| With [Minecraft Datapack Modrinth](https://github.com/FreshDoktor/Pelican-Minecraft-Datapack-Installer) | Modpacks, Datapacks | Modrinth |
| All three | Mods, Modpacks, Datapacks | Modrinth |

Only the tabs that fit the server are shown, a server with just one of them names the entry after it. If Minecraft Modrinth is enabled, its "Minecraft Mods" page leaves the navigation, its mods are the _Mods_ tab now. Minecraft Modrinth itself is not modified and can be updated as usual.

None of the plugins depends on another. This plugin, Minecraft Datapack Modrinth and Minecraft Mods Addon each ship the page (`src/ModrinthHub`), the first one enabled hosts it and the others add their tabs. Keep the copies in sync when changing it.

### Installed Mods

With _Only show installed_ turned on, the _Mods_ tab shows the installed list of Minecraft Modrinth. Install Minecraft Mods Addon to list every jar in the `mods` folder there instead, including the ones uploaded by hand, and to link them to Modrinth. It marks the mods placed by the modpack installed with this plugin.

## Configuration

| Env | Default | Description |
| --- | --- | --- |
| `MODRINTH_MODPACK_MAX_SIZE_MB` | `500` | Largest `.mrpack` the panel will download |
| `MODRINTH_MODPACK_JOB_TIMEOUT` | `2700` | Seconds a single operation may run |
| `MODRINTH_MODPACK_BACKUP_TIMEOUT` | `900` | Seconds to wait for a backup to finish |

Installed modpack data is kept in `.modrinth-modpack.json` in the server's root folder.

## License

Licensed under the [GNU General Public License v3.0](LICENSE).
