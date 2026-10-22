# Minecraft Modpack Modrinth (by Cedric Kassen)

Easily install, update, and manage Minecraft modpacks from Modrinth directly within the server panel.

## Setup

The modpack page uses the same egg feature as the mods page of the [Minecraft Modrinth](../minecraft-modrinth) plugin: add `modrinth_mods` to the _features_ of your egg (or `mods` together with the `minecraft` tag).
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name that supports modpacks (e.g. `fabric`, `quilt`, `forge` or `neoforge`).

Installing, updating and uninstalling modpacks runs in the background, so the panel's **queue worker must be running** (`pelican-queue` service, or `php artisan queue:work`).

The server itself is not changed: install the matching loader and Minecraft version through the egg yourself. Only modpack versions matching the server's loader and `MINECRAFT_VERSION` are listed.

### Together with Minecraft Modrinth

If the [Minecraft Modrinth](../minecraft-modrinth) plugin is installed too, its "Minecraft Mods" page is replaced in the navigation by one that adds the _Modpacks_ and _Installed Modpacks_ tabs, and the standalone modpack page disappears. Minecraft Modrinth itself is not modified and can be updated as usual.
The mods of an installed modpack are also listed in its _Installed Mods_ tab (as far as they are available on Modrinth).

## Features

- **Browse and Search**: Search Modrinth's modpacks compatible with your server's loader and Minecraft version
- **Background Installation**: Downloads every server-side file of the `.mrpack` and applies its `overrides` and `server-overrides`, with live progress on the page
- **Backups**: Optionally create a server backup before installing, updating or uninstalling; nothing is touched unless the backup succeeded
- **Update Detection**: Unchanged files are skipped on update, files dropped by the new version are removed
- **Easy Uninstall**: Removes every file the modpack placed, worlds and other files are kept
- **Safe by Default**: Only downloads from the hosts allowed by the `.mrpack` specification and rejects paths escaping the server directory
- **Multilingual**: Supports English and German translations

## Configuration

| Env | Default | Description |
| --- | --- | --- |
| `MODRINTH_MODPACK_MAX_SIZE_MB` | `500` | Largest `.mrpack` the panel will download |
| `MODRINTH_MODPACK_JOB_TIMEOUT` | `2700` | Seconds a single operation may run |
| `MODRINTH_MODPACK_BACKUP_TIMEOUT` | `900` | Seconds to wait for a backup to finish |

Installed modpack data is kept in `.modrinth-modpack.json` in the server's root folder.
