<?php

return [
    'plugin_name' => 'Modrinth Modpacks',

    'hub' => [
        'label' => 'Modpacks',
        'navigation_label' => 'Modrinth Modpacks',
    ],

    'page' => [
        'open_folder' => 'Dateimanager öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed_modpack' => 'Installiertes Modpack',
        'none' => 'Keins',
        'unknown' => 'Unbekannt',
        'modpack_unavailable' => 'Dieses Modpack ist auf Modrinth nicht mehr verfügbar',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Downloads',
        'published' => 'Veröffentlicht',
        'changelog' => 'Änderungsprotokoll',
    ],

    'actions' => [
        'install_latest' => 'Neueste Version installieren',
        'install' => 'Installieren',
        'installed' => 'Installiert',
        'update' => 'Aktualisieren',
        'uninstall' => 'Deinstallieren',
        'versions' => 'Versionsauswahl',
    ],

    'operations' => [
        'install' => 'Installation',
        'update' => 'Aktualisierung',
        'uninstall' => 'Deinstallation',
    ],

    'modals' => [
        'install_heading' => 'Modpack installieren',
        'install_description' => 'Dadurch wird :name in Version :version installiert und alle zugehörigen Dateien werden auf deinen Server heruntergeladen.',
        'replace_description' => 'Dadurch wird :old durch :new in Version :version ersetzt. Dateien von :old, die :new nicht enthält, werden gelöscht.',
        'update_heading' => 'Modpack aktualisieren',
        'update_description' => 'Dadurch wird Version :old_version durch Version :new_version ersetzt. Dateien, die die neue Version nicht mehr enthält, werden gelöscht und die Konfigurationsdateien des Modpacks werden überschrieben.',
        'uninstall_heading' => 'Modpack deinstallieren',
        'uninstall_description' => 'Möchtest du :name wirklich deinstallieren? Dadurch werden alle :count Dateien, die das Modpack auf deinem Server abgelegt hat, dauerhaft gelöscht, einschließlich der Konfigurationsdateien. Welten und andere Dateien bleiben erhalten.',
        'cancel_heading' => 'Vorgang abbrechen',
        'cancel_description' => 'Ein Vorgang in der Warteschlange startet nicht mehr. Ein laufender Vorgang stoppt nach der Datei, an der er gerade arbeitet; bereits geänderte Dateien bleiben unverändert. Führe die Installation oder Aktualisierung erneut aus, um das Modpack zu vervollständigen.',
        'stop_server_hint' => 'Es wird empfohlen, den Server vorher zu stoppen.',
    ],

    'backup' => [
        'toggle' => 'Vorher ein Backup erstellen',
        'helper' => 'Der Vorgang startet erst, nachdem das Backup erfolgreich abgeschlossen wurde.',
        'limit_reached' => 'Das Backup-Limit dieses Servers ist erreicht. Lösche ein Backup, um ein neues erstellen zu können.',
        'name' => 'Vor Modpack-:operation: :title',
    ],

    'progress' => [
        'heading' => 'Modpack-:operation: :name',
        'dismiss' => 'Ausblenden',
        'cancel' => 'Abbrechen',
        'status' => 'Status',
        'step' => 'Schritt',
        'message' => 'Meldung',
        'statuses' => [
            'queued' => 'In Warteschlange',
            'running' => 'Läuft',
            'done' => 'Fertig',
            'failed' => 'Fehlgeschlagen',
            'cancelled' => 'Abgebrochen',
        ],
        'steps' => [
            'queued' => 'Warte auf einen Queue-Worker',
            'queued_with_backup' => 'Warte auf einen Queue-Worker',
            'starting' => 'Starte',
            'backing_up' => 'Erstelle Backup',
            'downloading_pack' => 'Lade Modpack herunter',
            'installing_files' => 'Installiere Dateien',
            'applying_overrides' => 'Übernehme Konfigurationsdateien',
            'cleaning_up' => 'Entferne alte Dateien',
            'registering_mods' => 'Trage Mods in die installierten Mods ein',
            'removing_files' => 'Entferne Dateien',
            'done' => 'Abgeschlossen',
        ],
    ],

    'notifications' => [
        'cancelled' => 'Vorgang abgebrochen',
        'queued' => 'Vorgang gestartet',
        'queued_body' => ':name wird im Hintergrund verarbeitet. Den Fortschritt kannst du auf dieser Seite verfolgen.',
        'install_success' => 'Installation abgeschlossen',
        'install_success_body' => ':name Version :version wurde erfolgreich installiert',
        'install_failed' => 'Installation fehlgeschlagen',
        'update_success' => 'Aktualisierung abgeschlossen',
        'update_success_body' => ':name wurde erfolgreich auf Version :version aktualisiert',
        'update_failed' => 'Aktualisierung fehlgeschlagen',
        'uninstall_success' => 'Deinstallation abgeschlossen',
        'uninstall_success_body' => ':name wurde erfolgreich deinstalliert',
        'uninstall_failed' => 'Deinstallation fehlgeschlagen',
    ],

    'errors' => [
        'unexpected' => 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuche es erneut oder kontaktiere den Support, falls das Problem weiterhin besteht.',
        'cancelled' => 'Der Vorgang wurde abgebrochen.',
        'already_running' => 'Für diesen Server läuft bereits ein anderer Modpack-Vorgang.',
        'stale' => 'Der Vorgang meldet keinen Fortschritt mehr. Stelle sicher, dass der Queue-Worker des Panels läuft, und versuche es erneut.',
        'no_compatible_version' => 'Es wurde keine mit diesem Server kompatible Version gefunden.',
        'version_not_found' => 'Die ausgewählte Version wurde auf Modrinth nicht gefunden.',
        'no_mrpack' => 'Die ausgewählte Version enthält keine Modpack-Datei.',
        'invalid_mrpack' => 'Die Modpack-Datei ist ungültig oder verwendet ein nicht unterstütztes Format.',
        'mrpack_too_large' => 'Die Modpack-Datei überschreitet die konfigurierte Größenbeschränkung.',
        'invalid_download_url' => 'Das Modpack verweist auf einen nicht erlaubten Download-Ort.',
        'no_valid_download' => 'Für :path gibt es keinen erlaubten Download-Ort.',
        'invalid_path' => 'Das Modpack enthält einen ungültigen Pfad: :path',
        'download_failed' => 'Herunterladen von :path fehlgeschlagen: :reason',
        'write_failed' => 'Schreiben der Konfigurationsdatei :path (:size Bytes) fehlgeschlagen: :reason',
        'delete_failed' => 'Löschen von :path fehlgeschlagen: :reason',
        'daemon_unreachable' => 'Die Node ist nicht erreichbar.',
        'size_mismatch' => 'Die heruntergeladene Datei :path hat nicht die erwartete Größe.',
        'metadata_failed' => 'Die Dateien wurden installiert, aber das installierte Modpack konnte nicht gespeichert werden.',
        'not_installed' => 'Dieses Modpack ist nicht installiert.',
        'uninstall_partial' => 'Die Dateien von :name wurden gelöscht, aber es konnte nicht aus der Liste der installierten Modpacks entfernt werden. Es wird möglicherweise weiterhin als installiert angezeigt.',
        'backup_limit_reached' => 'Das Backup konnte nicht erstellt werden, da das Backup-Limit erreicht ist. Es wurde nichts geändert.',
        'backup_failed' => 'Das Backup ist fehlgeschlagen. Es wurde nichts geändert.',
        'backup_timeout' => 'Das Backup wurde nicht rechtzeitig fertig. Es wurde nichts geändert.',
    ],
];
