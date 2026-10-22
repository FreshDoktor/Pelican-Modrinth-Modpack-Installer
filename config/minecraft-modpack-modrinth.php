<?php

return [
    // Largest .mrpack file (in MB) the panel will download.
    'max_mrpack_size_mb' => env('MODRINTH_MODPACK_MAX_SIZE_MB', 500),

    // Seconds a single install/update/uninstall job may run.
    'job_timeout' => env('MODRINTH_MODPACK_JOB_TIMEOUT', 2700),

    // Seconds to wait for a backup created before an operation to finish.
    'backup_timeout' => env('MODRINTH_MODPACK_BACKUP_TIMEOUT', 900),

    // Hosts the files of a modpack may be downloaded from (see the .mrpack specification).
    'allowed_download_hosts' => [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ],
];
