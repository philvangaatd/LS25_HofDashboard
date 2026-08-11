<?php
declare(strict_types=1);

function handle_system_check(): void
{
    $checks = [];

    $checks[] = [
        'label' => 'Dashboard-Version',
        'status' => 'ok',
        'detail' => HOF_DASHBOARD_VERSION
            . ' · API-Protokoll '
            . HOF_DASHBOARD_PROTOCOL_MIN
            . (HOF_DASHBOARD_PROTOCOL_MAX !== HOF_DASHBOARD_PROTOCOL_MIN
                ? ('–' . HOF_DASHBOARD_PROTOCOL_MAX)
                : ''),
    ];

    $checks[] = [
        'label' => 'App-Datenordner',
        'status' => is_dir(APP_DATA_DIR) && is_writable(APP_DATA_DIR) ? 'ok' : 'error',
        'detail' => APP_DATA_DIR,
    ];

    $checks[] = [
        'label' => 'PHP-Version',
        'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'warn',
        'detail' => PHP_VERSION,
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "gd" (Bildverarbeitung)',
        'status' => extension_loaded('gd') ? 'ok' : 'error',
        'detail' => extension_loaded('gd') ? 'aktiviert' : 'fehlt – Kartenbild-Upload funktioniert nicht',
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "zip" (Backups, Mod-Kartensuche)',
        'status' => class_exists('ZipArchive') ? 'ok' : 'error',
        'detail' => class_exists('ZipArchive') ? 'aktiviert' : 'fehlt – vollständige Backups und automatische Kartensuche funktionieren nicht',
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "mbstring"',
        'status' => extension_loaded('mbstring') ? 'ok' : 'info',
        'detail' => extension_loaded('mbstring') ? 'aktiviert' : 'nicht aktiviert (Tool kommt bewusst ohne sie aus, kein Handlungsbedarf)',
    ];

    $uploadMax = ini_get('upload_max_filesize');
    $postMax = ini_get('post_max_size');
    $uploadBytes = (int)$uploadMax * (str_contains(strtoupper($uploadMax), 'M') ? 1024 * 1024 : 1);
    $checks[] = [
        'label' => 'Upload-Limit (upload_max_filesize / post_max_size)',
        'status' => $uploadBytes >= 8 * 1024 * 1024 ? 'ok' : 'warn',
        'detail' => "$uploadMax / $postMax" . ($uploadBytes < 8 * 1024 * 1024 ? ' – für große Kartenbilder ggf. zu klein, empfohlen mind. 8M' : ''),
    ];

    $iniPath = php_ini_loaded_file();
    $checks[] = [
        'label' => 'Geladene php.ini',
        'status' => $iniPath ? 'ok' : 'info',
        'detail' => $iniPath ?: 'keine php.ini geladen (nur Standardwerte aktiv)',
    ];

    $checks[] = [
        'label' => 'Spielstand-Ordner (FS_BASE_DIR)',
        'status' => is_dir(FS_BASE_DIR) && is_readable(FS_BASE_DIR) ? 'ok' : 'error',
        'detail' => FS_BASE_DIR,
    ];

    $checks[] = [
        'label' => 'Backup-Ordner beschreibbar',
        'status' => is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR) ? 'ok' : 'error',
        'detail' => BACKUP_DIR,
    ];

    $checks[] = [
        'label' => 'Kartenbild-Ordner beschreibbar',
        'status' => is_dir(MAP_ASSETS_DIR) && is_writable(MAP_ASSETS_DIR) ? 'ok' : 'error',
        'detail' => MAP_ASSETS_DIR,
    ];

    $modsDir = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods';
    $checks[] = [
        'label' => 'Mods-Ordner gefunden',
        'status' => is_dir($modsDir) ? 'ok' : 'info',
        'detail' => is_dir($modsDir) ? $modsDir : 'nicht gefunden (nur relevant für automatische Kartenbild-Suche bei Mod-Karten)',
    ];

    $installDir = defined('FS_INSTALL_DIR') ? FS_INSTALL_DIR : '';
    $checks[] = [
        'label' => 'Spiel-Installationsordner (FS_INSTALL_DIR)',
        'status' => $installDir !== '' ? 'ok' : 'info',
        'detail' => $installDir !== '' ? $installDir : 'nicht automatisch gefunden (nur relevant für automatische Kartenbild-Suche bei offiziellen Karten ohne Mod-Datei) – manuell setzbar über FS_INSTALL_DIR_OVERRIDE in config.php',
    ];

    $checks[] = [
        'label' => 'Zeitzone',
        'status' => 'ok',
        'detail' => date_default_timezone_get() . ' · Serverzeit: ' . date('d.m.Y H:i:s'),
    ];

    api_json_response(['checks' => $checks]);
}
