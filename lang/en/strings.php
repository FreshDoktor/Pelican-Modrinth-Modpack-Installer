<?php

return [
    'plugin_name' => 'Modrinth Modpacks',

    'hub' => [
        'label' => 'Modpacks',
        'navigation_label' => 'Modrinth Modpacks',
    ],

    'page' => [
        'open_folder' => 'Open file manager',
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'installed_modpack' => 'Installed Modpack',
        'none' => 'None',
        'unknown' => 'Unknown',
        'modpack_unavailable' => 'This modpack is no longer available on Modrinth',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Downloads',
        'published' => 'Published',
        'changelog' => 'Changelog',
    ],

    'actions' => [
        'install_latest' => 'Install latest version',
        'install' => 'Install',
        'installed' => 'Installed',
        'update' => 'Update',
        'uninstall' => 'Uninstall',
        'versions' => 'Version Selection',
    ],

    'operations' => [
        'install' => 'install',
        'update' => 'update',
        'uninstall' => 'uninstall',
    ],

    'modals' => [
        'install_heading' => 'Install Modpack',
        'install_description' => 'This will install :name version :version and download all of its files to your server.',
        'replace_description' => 'This will replace :old with :new version :version. Files of :old that :new doesn\'t ship will be deleted.',
        'update_heading' => 'Update Modpack',
        'update_description' => 'This will replace version :old_version with version :new_version. Files the new version no longer ships will be deleted and the config files of the pack will be overwritten.',
        'uninstall_heading' => 'Uninstall Modpack',
        'uninstall_description' => 'Are you sure you want to uninstall :name? This will permanently delete all :count files the modpack placed on your server, including its config files. Worlds and other files are kept.',
        'cancel_heading' => 'Cancel Operation',
        'cancel_description' => 'A queued operation won\'t start. A running one stops after the file it is currently working on, files it already changed stay as they are. Run the install or update again to complete the modpack.',
        'stop_server_hint' => 'It is recommended to stop the server first.',
    ],

    'backup' => [
        'toggle' => 'Create a backup first',
        'helper' => 'The operation only starts once the backup has completed successfully.',
        'limit_reached' => 'The backup limit of this server is reached. Delete a backup to be able to create one.',
        'name' => 'Before modpack :operation: :title',
    ],

    'progress' => [
        'heading' => 'Modpack :operation: :name',
        'dismiss' => 'Dismiss',
        'cancel' => 'Cancel',
        'status' => 'Status',
        'step' => 'Step',
        'message' => 'Message',
        'statuses' => [
            'queued' => 'Queued',
            'running' => 'Running',
            'done' => 'Done',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
        ],
        'steps' => [
            'queued' => 'Waiting for a queue worker',
            'queued_with_backup' => 'Waiting for a queue worker',
            'starting' => 'Starting',
            'backing_up' => 'Creating backup',
            'downloading_pack' => 'Downloading modpack',
            'installing_files' => 'Installing files',
            'applying_overrides' => 'Applying config files',
            'cleaning_up' => 'Removing old files',
            'registering_mods' => 'Listing mods in the installed mods',
            'removing_files' => 'Removing files',
            'done' => 'Finished',
        ],
    ],

    'notifications' => [
        'cancelled' => 'Operation cancelled',
        'queued' => 'Operation started',
        'queued_body' => ':name is being processed in the background. You can follow the progress on this page.',
        'install_success' => 'Installation completed',
        'install_success_body' => 'Successfully installed :name version :version',
        'install_failed' => 'Installation failed',
        'update_success' => 'Update completed',
        'update_success_body' => 'Successfully updated :name to version :version',
        'update_failed' => 'Update failed',
        'uninstall_success' => 'Uninstall completed',
        'uninstall_success_body' => 'Successfully uninstalled :name',
        'uninstall_failed' => 'Uninstall failed',
    ],

    'errors' => [
        'unexpected' => 'An unexpected error occurred. Please try again or contact support if the issue persists.',
        'cancelled' => 'The operation was cancelled.',
        'already_running' => 'Another modpack operation is already running for this server.',
        'stale' => 'The operation stopped reporting progress. Make sure the panel\'s queue worker is running and try again.',
        'no_compatible_version' => 'No version compatible with this server was found.',
        'version_not_found' => 'The selected version could not be found on Modrinth.',
        'no_mrpack' => 'The selected version has no modpack file.',
        'invalid_mrpack' => 'The modpack file is invalid or uses an unsupported format.',
        'mrpack_too_large' => 'The modpack file exceeds the configured size limit.',
        'invalid_download_url' => 'The modpack points to a download location that is not allowed.',
        'no_valid_download' => 'There is no allowed download location for :path.',
        'invalid_path' => 'The modpack contains an invalid path: :path',
        'download_failed' => 'Downloading :path failed: :reason',
        'write_failed' => 'Writing the config file :path (:size bytes) failed: :reason',
        'daemon_unreachable' => 'The node could not be reached.',
        'size_mismatch' => 'The downloaded file :path does not have the expected size.',
        'metadata_failed' => 'The files were installed, but the installed modpack could not be saved.',
        'not_installed' => 'This modpack is not installed.',
        'uninstall_partial' => 'The files of :name were deleted, but it could not be removed from the installed list. It may still appear as installed.',
        'backup_limit_reached' => 'The backup could not be created because the backup limit is reached. Nothing was changed.',
        'backup_failed' => 'The backup failed. Nothing was changed.',
        'backup_timeout' => 'The backup did not finish in time. Nothing was changed.',
    ],
];
