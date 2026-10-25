# Changelog

## Unreleased

### Fixed

- The pagination of the modpack list works again: going to another page no longer stays on the first one

## 1.1.0 - 2026-10-24

### Added

- Installing, updating, uninstalling and cancelling a modpack shows up in the server's activity log together with the user who did it, as does the backup created beforehand

### Fixed

- A failed file deletion during uninstall or update no longer ends in a generic "unexpected error": the deletion is retried file by file, and if one still fails the message names the file and the node's reason

## 1.0.1 - 2026-10-24

### Removed

- The _Installed Mods_ overview: listing every jar in the `mods` folder including uploaded mods, analysing them in the background, linking them to Modrinth, and updating, unlinking and deleting them from the list
- The `MODRINTH_MODPACK_MAX_MOD_SCAN_SIZE_MB` setting, which only applied to that overview

## 1.0.0

- Initial release
