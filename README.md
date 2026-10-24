# Minecraft Modpack Modrinth

A [Pelican Panel](https://pelican.dev) plugin to install, update and manage Minecraft modpacks from [Modrinth](https://modrinth.com) directly within the server panel, and to keep track of every mod on the server, including the ones uploaded by hand.

Originally written by Cedric Kassen, extended with the _Installed Mods_ management by [FreshGaming](https://github.com/freshgaming-eu).

## Features

### Modpacks

- **Browse and Search**: Search Modrinth's modpacks compatible with your server's loader and Minecraft version
- **Background Installation**: Downloads every server-side file of the `.mrpack` and applies its `overrides` and `server-overrides`, with live progress on the page; running operations can be cancelled
- **Backups**: Optionally create a server backup before installing, updating or uninstalling; nothing is touched unless the backup succeeded
- **Update Detection**: Unchanged files are skipped on update, files dropped by the new version are removed
- **Easy Uninstall**: Removes every file the modpack placed, worlds and other files are kept
- **Safe by Default**: Only downloads from the hosts allowed by the `.mrpack` specification and rejects paths escaping the server directory

### Installed Mods

- **Every jar in the `mods` folder**: Lists not only the mods installed from Modrinth, but also the ones placed by a modpack or uploaded by hand
- **Background Analysis**: Reads name, version, loader, supported Minecraft version and client-only mods from the jar itself; mods made for another loader than the server's are highlighted
- **Link to Modrinth**: Uploaded mods can be linked to a Modrinth project, mods Modrinth recognises by their hash can be linked all at once; linked mods are updated like any other Modrinth mod
- **Manage each mod**: Update, link, unlink and delete mods right from the list

### General

- **Integrates with Minecraft Modrinth**: Combines mods and modpacks on one page if the Minecraft Modrinth plugin is installed too
- **Multilingual**: Supports English and German translations

## Requirements

- Pelican Panel with plugin support
- A Minecraft egg with a loader supported by Modrinth modpacks (Fabric, Quilt, Forge or NeoForge)
- A running queue worker (see [Setup](#setup))
- Optional: the [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) plugin

## Installation

Clone the repository into the `plugins` folder of your panel. The folder has to be named like the plugin id:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/freshgaming-eu/Pelican-Modrinth-Modpack-Installer.git minecraft-modpack-modrinth
```

Then install and enable the plugin in the admin area of the panel under _Plugins_.

## Setup

The modpack page uses the same egg feature as the mods page of the Minecraft Modrinth plugin: add `modrinth_mods` to the _features_ of your egg (or `mods` together with the `minecraft` tag).
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name that supports modpacks (e.g. `fabric`, `quilt`, `forge` or `neoforge`).

Installing, updating and uninstalling modpacks as well as analysing uploaded mods runs in the background, so the panel's **queue worker must be running** (`pelican-queue` service, or `php artisan queue:work`).

The server itself is not changed: install the matching loader and Minecraft version through the egg yourself. Only modpack versions matching the server's loader and `MINECRAFT_VERSION` are listed.

### Together with Minecraft Modrinth

If the Minecraft Modrinth plugin is installed too, its "Minecraft Mods" page is replaced in the navigation by one that adds the _Modpacks_, _Installed Mods_ and _Installed Modpacks_ tabs, and the standalone modpack page disappears. Minecraft Modrinth itself is not modified and can be updated as usual.

### Installed Mods

The _Installed Mods_ tab lists **every jar in the `mods` folder**, not only the ones installed from Modrinth:

| Source | Meaning |
| --- | --- |
| Modrinth | Linked to a Modrinth project, updates are offered |
| Modpack | Placed by the installed modpack |
| Found on Modrinth | Uploaded by hand, Modrinth knows this exact file (by its SHA-1 hash) |
| Uploaded | Uploaded by hand, not found on Modrinth |
| Analysing | Not read yet |
| File missing | Listed as installed, but the jar is gone |

Uploaded mods are read in the background by the queue worker: name, mod id, version, loader, supported Minecraft version and client-only mods are taken from the metadata inside the jar (`fabric.mod.json`, `quilt.mod.json`, `META-INF/mods.toml`, `META-INF/neoforge.mods.toml`, `mcmod.info`), falling back to the file name. Mods made for another loader than the server's are highlighted.

Any uploaded mod can be **linked to a Modrinth project** afterwards (search by name or paste a Modrinth link). Mods Modrinth recognised are pre-selected with their exact version and can be linked all at once. Linking adds the mod to the installed list of Minecraft Modrinth (`mods/.modrinth-metadata.json`), from then on it is updated like a mod installed from Modrinth, in this tab as well as in the _Mods_ tab. If the installed version is unknown, every compatible version is offered as an update.

## Configuration

| Env | Default | Description |
| --- | --- | --- |
| `MODRINTH_MODPACK_MAX_SIZE_MB` | `500` | Largest `.mrpack` the panel will download |
| `MODRINTH_MODPACK_JOB_TIMEOUT` | `2700` | Seconds a single operation may run |
| `MODRINTH_MODPACK_BACKUP_TIMEOUT` | `900` | Seconds to wait for a backup to finish |
| `MODRINTH_MODPACK_MAX_MOD_SCAN_SIZE_MB` | `64` | Largest mod jar that is read to identify it, bigger ones are only judged by their file name |

Installed modpack data is kept in `.modrinth-modpack.json` in the server's root folder. What was read from uploaded mods is cached in the panel's cache, _Analyse again_ in the _Installed Mods_ tab throws it away.

## License

Licensed under the [GNU General Public License v3.0](LICENSE).
